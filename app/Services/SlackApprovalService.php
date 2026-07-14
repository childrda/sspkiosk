<?php

namespace App\Services;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Enums\StudentPhotoType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\StudentPhoto;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackPhotoUrlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SlackApprovalService
{
    public const ACTION_APPROVE = 'ssp_approve_reset';

    public const ACTION_DENY = 'ssp_deny_reset';

    public const ACTION_OFFICE = 'ssp_needs_office_verification';

    public function __construct(
        private readonly SlackApiClient $slackApi,
        private readonly SlackPhotoUrlService $slackPhotoUrls,
        private readonly SlackApproverService $approverService,
        private readonly PasswordResetRequestRiskService $riskService,
        private readonly ResetAttemptLimiterService $attemptLimiter,
        private readonly AuditLogService $auditLog,
        private readonly PendingPasswordService $pendingPasswords,
    ) {}

    public function sendApprovalMessage(PasswordResetRequest $request): void
    {
        if (! config('student-password-reset.slack_approval_required')) {
            return;
        }

        $request->load(['student', 'kiosk', 'resetPhoto']);

        $channelId = (string) config('slack.reset_channel_id');
        $student = $request->student;
        $kiosk = $request->kiosk;

        $registrationPhoto = $student->photos()
            ->where('type', StudentPhotoType::Registration)
            ->latest('id')
            ->first();

        $asked = count($request->challenge_questions_presented ?? []);
        $required = config('student-password-reset.challenge_questions_required_correct');
        $flags = $this->riskService->flagsFor($request);
        $lastApproved = PasswordResetRequest::query()
            ->where('student_id', $student->id)
            ->where('status', PasswordResetRequestStatus::Completed)
            ->where('id', '!=', $request->id)
            ->latest('approved_at')
            ->first();

        $blocks = $this->buildMessageBlocks(
            request: $request,
            statusLabel: 'Pending approval',
            includeActions: true,
            photoBlocks: $this->photoBlocksForApproval($request->resetPhoto, $registrationPhoto, $student->name),
            extraFields: [
                '*Student*' => "{$student->name} ({$student->email})",
                '*School / grade*' => trim(($student->school ?? '—').' / '.($student->grade ?? '—')),
                '*Kiosk*' => "{$kiosk->name}".($kiosk->location ? " — {$kiosk->location}" : ''),
                '*Requested*' => $request->requested_at->toDayDateTimeString(),
                '*Expires*' => $request->expires_at->toDayDateTimeString(),
                '*Kiosk IP / device*' => $request->resetPhoto?->metadata['ip_address'] ?? '—',
                '*Challenge result*' => "{$request->challenge_score} of {$asked} matched ({$required} required)",
                '*Failed attempts today (student)*' => (string) $this->attemptLimiter->failedAttemptsForStudentToday($student),
                '*Failed attempts today (kiosk)*' => (string) $this->attemptLimiter->failedAttemptsForKioskToday($kiosk),
                '*Last approved reset*' => $lastApproved?->approved_at?->toDayDateTimeString() ?? 'Never',
                '*Risk flags*' => $flags === [] ? 'None' : implode('; ', $flags),
                '*Password handling*' => $this->passwordHandlingSummary($request),
            ],
        );

        $response = $this->slackApi->postMessage([
            'channel' => $channelId,
            'text' => "Password reset request #{$request->id} for {$student->name}",
            'blocks' => $blocks,
        ]);

        if (! ($response['ok'] ?? false)) {
            Log::error('Slack chat.postMessage failed.', [
                'request_id' => $request->id,
                'error' => $response['error'] ?? 'unknown',
            ]);

            throw new \RuntimeException('Failed to send Slack approval message.');
        }

        $messageTs = $response['ts'] ?? null;

        $request->update([
            'slack_channel_id' => $response['channel'] ?? $channelId,
            'slack_message_ts' => $messageTs,
        ]);

        if ($messageTs !== null) {
            $this->uploadPhotoFallbackToThread(
                $channelId,
                $messageTs,
                $request->resetPhoto,
                'Kiosk reset photo — '.$student->name,
            );
            $this->uploadPhotoFallbackToThread(
                $channelId,
                $messageTs,
                $registrationPhoto,
                'Registration photo — '.$student->name,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function handleInteraction(array $payload): array
    {
        if (($payload['type'] ?? '') === 'url_verification') {
            return ['challenge' => $payload['challenge'] ?? ''];
        }

        if (($payload['type'] ?? '') !== 'block_actions') {
            return [];
        }

        $slackUserId = (string) ($payload['user']['id'] ?? '');

        if (! $this->approverService->isAuthorizedApprover($slackUserId)) {
            return [
                'response_type' => 'ephemeral',
                'text' => 'You are not authorized to approve password resets.',
            ];
        }

        $action = $payload['actions'][0] ?? [];
        $actionId = (string) ($action['action_id'] ?? '');
        $requestId = (int) ($action['value'] ?? 0);

        $resetRequest = PasswordResetRequest::query()->find($requestId);

        if (! $resetRequest) {
            return [
                'response_type' => 'ephemeral',
                'text' => 'This reset request was not found.',
            ];
        }

        return match ($actionId) {
            self::ACTION_APPROVE => $this->processApprove($resetRequest, $slackUserId, $payload),
            self::ACTION_DENY => $this->processDeny($resetRequest, $slackUserId, $payload),
            self::ACTION_OFFICE => $this->processOfficeVerification($resetRequest, $slackUserId, $payload),
            default => [
                'response_type' => 'ephemeral',
                'text' => 'Unknown action.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function processApprove(PasswordResetRequest $request, string $slackUserId, array $payload): array
    {
        if (! $this->assertCanDecide($request)) {
            return $this->alreadyDecidedResponse($request);
        }

        if ($request->isExpired()) {
            $request->update(['status' => PasswordResetRequestStatus::Expired]);
            $this->pendingPasswords->delete($request, 'expiration');

            $this->refreshSlackMessage($request, 'Expired', $slackUserId);

            return [
                'response_type' => 'ephemeral',
                'text' => 'This request has expired.',
            ];
        }

        if (! $this->pendingPasswords->hasEncryptedPendingPassword($request)) {
            return [
                'response_type' => 'ephemeral',
                'text' => 'This request has no pending password on file.',
            ];
        }

        DB::transaction(function () use ($request, $slackUserId): void {
            $request->refresh();

            if (! $request->isPending()) {
                return;
            }

            $request->update([
                'status' => PasswordResetRequestStatus::ApprovedProcessing,
                'approved_by_slack_user_id' => $slackUserId,
                'approved_at' => now(),
            ]);
        });

        $request->refresh();

        if ($request->status !== PasswordResetRequestStatus::ApprovedProcessing) {
            return $this->alreadyDecidedResponse($request);
        }

        $this->auditLog->logTech('slack.reset.approved', $slackUserId, 'password_reset_request', (string) $request->id);
        $this->refreshSlackMessage($request, 'Approved — processing', $slackUserId);
        ResetDirectoryPasswordsJob::dispatch($request->id);

        return [
            'response_type' => 'ephemeral',
            'text' => 'Reset approved. Directory password reset will run shortly.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function processDeny(PasswordResetRequest $request, string $slackUserId, array $payload): array
    {
        if (! $this->assertCanDecide($request)) {
            return $this->alreadyDecidedResponse($request);
        }

        $request->update([
            'status' => PasswordResetRequestStatus::Denied,
            'denied_by_slack_user_id' => $slackUserId,
            'denied_at' => now(),
        ]);

        $this->pendingPasswords->delete($request, 'denial');

        $this->auditLog->logTech('slack.reset.denied', $slackUserId, 'password_reset_request', (string) $request->id);
        $this->refreshSlackMessage($request, 'Denied', $slackUserId);

        return [
            'response_type' => 'ephemeral',
            'text' => 'Request denied.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function processOfficeVerification(PasswordResetRequest $request, string $slackUserId, array $payload): array
    {
        if (! config('student-password-reset.office_verification_allowed')) {
            return [
                'response_type' => 'ephemeral',
                'text' => 'Office verification is not enabled.',
            ];
        }

        if (! $this->assertCanDecide($request)) {
            return $this->alreadyDecidedResponse($request);
        }

        $request->update([
            'status' => PasswordResetRequestStatus::NeedsOfficeVerification,
            'escalated_at' => now(),
            'escalated_by_slack_user_id' => $slackUserId,
            'office_verification_expires_at' => now()->addHours(
                (int) config('student-password-reset.office_verification_expires_hours', 48),
            ),
        ]);

        $this->pendingPasswords->delete($request, 'escalation');

        $this->auditLog->logTech('slack.reset.office_verification', $slackUserId, 'password_reset_request', (string) $request->id);
        $this->refreshSlackMessage($request, 'Needs office verification', $slackUserId);

        return [
            'response_type' => 'ephemeral',
            'text' => 'Marked for office verification.',
        ];
    }

    private function assertCanDecide(PasswordResetRequest $request): bool
    {
        return in_array($request->status, [
            PasswordResetRequestStatus::Pending,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyDecidedResponse(PasswordResetRequest $request): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => 'This request was already '.$request->status->value.'.',
        ];
    }

    public function appendGoogleResetStatus(PasswordResetRequest $request, bool $success): void
    {
        $this->appendDirectoryResetStatus($request);
    }

    public function appendDirectoryResetStatus(PasswordResetRequest $request): void
    {
        if ($request->slack_channel_id === null || $request->slack_message_ts === null) {
            return;
        }

        $request->load(['student', 'kiosk']);
        $request->refresh();

        $results = $request->directory_results['results'] ?? [];
        $google = $results['google']['status'] ?? 'pending';
        $ad = $results['active_directory']['status'] ?? 'pending';
        $adReason = $results['active_directory']['reason'] ?? null;

        $statusLabel = match ($request->status) {
            \App\Enums\PasswordResetRequestStatus::Completed => 'Completed — directories updated',
            \App\Enums\PasswordResetRequestStatus::PartiallyCompleted => 'Partially completed — action needed',
            \App\Enums\PasswordResetRequestStatus::Failed => 'Failed — directory reset error',
            default => 'Processing — directory reset in progress',
        };

        $adLine = match ($ad) {
            'success' => 'Active Directory password reset completed.',
            'failed' => $adReason === 'policy_rejected'
                ? 'Active Directory rejected the selected password (policy). An administrator must start password replacement so the student can choose a different password at the kiosk.'
                : 'Active Directory password reset failed ('.$adReason.').',
            'skipped' => 'Active Directory skipped ('.$adReason.').',
            default => 'Active Directory pending.',
        };

        $googleLine = match ($google) {
            'success' => 'Google Workspace password reset completed.',
            'failed' => 'Google Workspace password reset failed.',
            'skipped' => 'Google Workspace skipped.',
            default => 'Google Workspace pending.',
        };

        $blocks = $this->buildMessageBlocks(
            request: $request,
            statusLabel: $statusLabel,
            includeActions: false,
            extraFields: [
                '*Google*' => $googleLine,
                '*Active Directory*' => $adLine,
                '*Student*' => "{$request->student->name} ({$request->student->email})",
                '*Approved by*' => $request->approved_by_slack_user_id
                    ? "<@{$request->approved_by_slack_user_id}>"
                    : '—',
            ],
        );

        $this->slackApi->updateMessage([
            'channel' => $request->slack_channel_id,
            'ts' => $request->slack_message_ts,
            'text' => "Password reset request #{$request->id} — {$statusLabel}",
            'blocks' => $blocks,
        ]);
    }

    public function notifyOfficeOutcome(
        PasswordResetRequest $request,
        string $statusLabel,
        \App\Models\User $admin,
    ): void {
        if ($request->slack_channel_id === null || $request->slack_message_ts === null) {
            return;
        }

        $request->load(['student', 'kiosk']);

        $extraFields = [
            '*Outcome*' => $statusLabel,
            '*Admin*' => $admin->name.' ('.$admin->email.')',
            '*Resolved at*' => now()->toDayDateTimeString(),
            '*Student*' => "{$request->student->name} ({$request->student->email})",
        ];

        if ($request->office_verification_notes) {
            $extraFields['*Office notes*'] = $request->office_verification_notes;
        }

        if ($request->denial_reason) {
            $extraFields['*Rejection reason*'] = $request->denial_reason;
        }

        $blocks = $this->buildMessageBlocks(
            request: $request,
            statusLabel: $statusLabel,
            includeActions: false,
            extraFields: $extraFields,
        );

        $this->slackApi->updateMessage([
            'channel' => $request->slack_channel_id,
            'ts' => $request->slack_message_ts,
            'text' => "Password reset request #{$request->id} — {$statusLabel}",
            'blocks' => $blocks,
        ]);
    }

    private function refreshSlackMessage(PasswordResetRequest $request, string $statusLabel, string $actingSlackUserId): void
    {
        if ($request->slack_channel_id === null || $request->slack_message_ts === null) {
            return;
        }

        $request->load(['student', 'kiosk']);

        $blocks = $this->buildMessageBlocks(
            request: $request,
            statusLabel: $statusLabel,
            includeActions: false,
            extraFields: [
                '*Decision by*' => "<@{$actingSlackUserId}>",
                '*Decision at*' => now()->toDayDateTimeString(),
                '*Student*' => "{$request->student->name} ({$request->student->email})",
            ],
        );

        $this->slackApi->updateMessage([
            'channel' => $request->slack_channel_id,
            'ts' => $request->slack_message_ts,
            'text' => "Password reset request #{$request->id} — {$statusLabel}",
            'blocks' => $blocks,
        ]);
    }

    /**
     * @param  array<string, string>  $extraFields
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function photoBlocksForApproval(
        ?StudentPhoto $resetPhoto,
        ?StudentPhoto $registrationPhoto,
        string $studentName,
    ): array {
        $blocks = [];

        $resetUrl = $this->slackPhotoUrls->temporarySignedUrl($resetPhoto);

        if ($resetUrl !== null) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Photo at kiosk (reset request)*',
                ],
            ];
            $blocks[] = [
                'type' => 'image',
                'image_url' => $resetUrl,
                'alt_text' => "Reset request photo for {$studentName}",
            ];
        }

        $registrationUrl = $this->slackPhotoUrls->temporarySignedUrl($registrationPhoto);

        if ($registrationUrl !== null) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Registration photo (on file)*',
                ],
            ];
            $blocks[] = [
                'type' => 'image',
                'image_url' => $registrationUrl,
                'alt_text' => "Registration photo for {$studentName}",
            ];
        }

        return $blocks;
    }

    private function buildMessageBlocks(
        PasswordResetRequest $request,
        string $statusLabel,
        bool $includeActions,
        array $extraFields,
        array $photoBlocks = [],
    ): array {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => "Password reset request #{$request->id}",
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Status:* {$statusLabel}",
                ],
            ],
        ];

        $lines = [];

        foreach ($extraFields as $label => $value) {
            $lines[] = "{$label}\n{$value}";
        }

        foreach (array_chunk($lines, 10) as $chunk) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode("\n\n", $chunk),
                ],
            ];
        }

        array_push($blocks, ...$photoBlocks);

        if ($includeActions) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Approve Reset'],
                        'style' => 'primary',
                        'action_id' => self::ACTION_APPROVE,
                        'value' => (string) $request->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Deny Request'],
                        'style' => 'danger',
                        'action_id' => self::ACTION_DENY,
                        'value' => (string) $request->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Needs Office Verification'],
                        'action_id' => self::ACTION_OFFICE,
                        'value' => (string) $request->id,
                    ],
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => 'Passwords are never posted in Slack.',
                ],
            ],
        ];

        return $blocks;
    }

    private function passwordHandlingSummary(PasswordResetRequest $request): string
    {
        if ($request->pending_password_type === PendingPasswordType::StudentSelected->value) {
            return 'Student selected a new password at the kiosk. It is encrypted and will only be sent to Google if approved. The password is not shown in Slack.';
        }

        return 'System generated a temporary password and showed it to the student. It is encrypted and will only become active if this request is approved. The password is not shown in Slack.';
    }

    /**
     * Thread file upload when inline image blocks are unavailable (non-HTTPS APP_URL) or as backup.
     */
    private function uploadPhotoFallbackToThread(
        string $channelId,
        string $threadTs,
        ?StudentPhoto $photo,
        string $title,
    ): void {
        if ($photo === null) {
            return;
        }

        if ($this->slackPhotoUrls->temporarySignedUrl($photo) !== null) {
            return;
        }

        $disk = (string) config('student-password-reset.photo_storage_disk');

        if (! Storage::disk($disk)->exists($photo->storage_path)) {
            return;
        }

        $contents = Storage::disk($disk)->get($photo->storage_path);
        $filename = basename($photo->storage_path);

        $response = $this->slackApi->uploadFile($channelId, $filename, $contents, $title, $threadTs);

        if (! ($response['ok'] ?? false)) {
            Log::warning('Slack photo upload failed.', [
                'photo_id' => $photo->id,
                'error' => $response['error'] ?? 'unknown',
            ]);
        }
    }
}

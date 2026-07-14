<?php

namespace App\Services;

use App\Contracts\DirectoryPasswordResetter;
use App\Enums\DirectoryRetryMode;
use App\Enums\PasswordResetRequestStatus;
use App\Enums\PasswordResetRevisionStatus;
use App\Exceptions\DirectoryResetException;
use App\Models\PasswordResetRequest;
use App\Models\PasswordResetRevision;
use App\Support\DirectoryResetOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DirectoryPasswordResetCoordinator
{
    /** @var array<string, true> */
    private array $attemptedThisRun = [];

    /**
     * @param  iterable<int, DirectoryPasswordResetter>  $directoryResetters
     */
    public function __construct(
        private readonly iterable $directoryResetters,
        private readonly PendingPasswordService $pendingPasswords,
        private readonly AuditLogService $auditLog,
        private readonly SlackApprovalService $slackApproval,
        private readonly PasswordRevisionService $revisions,
    ) {}

    public function process(int $passwordResetRequestId): DirectoryResetOutcome
    {
        $this->attemptedThisRun = [];

        $claimed = $this->claimNextDirectory($passwordResetRequestId);

        while ($claimed !== null) {
            $this->executeClaim($claimed['request_id'], $claimed['revision_id'], $claimed['directory_key'], $claimed['force_change']);
            $claimed = $this->claimNextDirectory($passwordResetRequestId);
        }

        return $this->buildOutcome($passwordResetRequestId);
    }

    /**
     * @return array{request_id: int, revision_id: int, directory_key: string, force_change: bool}|null
     */
    private function claimNextDirectory(int $requestId): ?array
    {
        return DB::transaction(function () use ($requestId): ?array {
            $request = PasswordResetRequest::query()
                ->lockForUpdate()
                ->with('student')
                ->find($requestId);

            if ($request === null) {
                return null;
            }

            if (! $this->isEligible($request)) {
                return null;
            }

            $revision = $this->revisions->activeRevision($request);

            if ($revision === null) {
                return null;
            }

            // Authority: revision fields win even if request projection diverges.
            if ($revision->pendingPasswordExpired()) {
                $this->expirePendingCredential($request, $revision);

                return null;
            }

            if (! $revision->hasEncryptedPendingPassword()) {
                $revision->forceFill(['retry_available' => false])->save();
                $request->forceFill([
                    'status' => PasswordResetRequestStatus::Failed,
                    'retry_available' => false,
                    'google_reset_attempted_at' => $request->google_reset_attempted_at ?? now(),
                    'google_reset_success' => false,
                    'google_error_message' => 'Pending password unavailable.',
                ])->save();
                $this->revisions->projectToRequest($request, $revision);

                return null;
            }

            $this->ensureDirectorySnapshot($request, $revision);
            $this->reclaimStaleProcessing($request, $revision);

            $key = $this->selectNextDirectoryKey($revision);

            if ($key === null) {
                $this->recalculateStatusAndRetry($request, $revision);
                $this->syncLegacyGoogleColumns($request, $revision);
                $revision->save();
                $request->save();
                $this->revisions->projectToRequest($request, $revision);

                return null;
            }

            $results = $revision->directory_results ?? [];
            $results['results'][$key]['status'] = 'processing';
            $results['results'][$key]['processing_started_at'] = now()->toIso8601String();
            $revision->directory_results = $results;
            $revision->save();
            $this->revisions->projectToRequest($request, $revision);

            return [
                'request_id' => $request->id,
                'revision_id' => $revision->id,
                'directory_key' => $key,
                'force_change' => (bool) $revision->force_change_at_next_login,
            ];
        });
    }

    private function executeClaim(int $requestId, int $revisionId, string $directoryKey, bool $forceChange): void
    {
        $request = PasswordResetRequest::query()->with('student')->find($requestId);
        $revision = PasswordResetRevision::query()->find($revisionId);

        if ($request === null || $revision === null) {
            return;
        }

        $plainPassword = $this->pendingPasswords->decryptRevision($revision);

        if ($plainPassword === null) {
            DB::transaction(function () use ($requestId, $revisionId, $directoryKey): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->find($requestId);
                $revision = PasswordResetRevision::query()->lockForUpdate()->find($revisionId);
                if ($locked === null || $revision === null) {
                    return;
                }
                $this->recordFailure($revision, $directoryKey, 'unexpected_error', DirectoryRetryMode::None);
                $this->recalculateStatusAndRetry($locked, $revision);
                $this->syncLegacyGoogleColumns($locked, $revision);
                $revision->save();
                $locked->save();
                $this->revisions->projectToRequest($locked, $revision);
            });

            return;
        }

        $resetter = $this->resetterByKey($directoryKey);

        try {
            if ($resetter === null) {
                throw new DirectoryResetException(
                    'Directory resetter unavailable.',
                    'configuration_error',
                    DirectoryRetryMode::None,
                );
            }

            $resetter->resetPassword($request->student, $plainPassword, $forceChange);

            DB::transaction(function () use ($requestId, $revisionId, $directoryKey): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->with('student')->find($requestId);
                $revision = PasswordResetRevision::query()->lockForUpdate()->find($revisionId);
                if ($locked === null || $revision === null) {
                    return;
                }

                $results = $revision->directory_results ?? [];
                if (($results['results'][$directoryKey]['status'] ?? null) !== 'processing') {
                    return;
                }

                $attempts = (int) ($results['results'][$directoryKey]['attempts'] ?? 0);
                $results['results'][$directoryKey] = array_merge($results['results'][$directoryKey], [
                    'status' => 'success',
                    'reason' => null,
                    'retry_mode' => DirectoryRetryMode::None->value,
                    'attempts' => $attempts + 1,
                    'last_attempt_at' => now()->toIso8601String(),
                    'processing_started_at' => null,
                    'completed_at' => now()->toIso8601String(),
                ]);
                $revision->directory_results = $results;
                $this->recalculateStatusAndRetry($locked, $revision);
                $this->syncLegacyGoogleColumns($locked, $revision);

                if ($locked->status === PasswordResetRequestStatus::Completed) {
                    $this->pendingPasswords->deleteRevision($revision, 'approval');
                    $revision->forceFill([
                        'status' => PasswordResetRevisionStatus::Completed,
                        'retry_available' => false,
                        'active_for_request_id' => null,
                    ])->save();
                    $this->auditLog->logSystem('password_revision.completed', 'password_reset_request', (string) $locked->id, [
                        'revision_number' => $revision->revision_number,
                    ]);
                }

                $revision->save();
                $locked->save();
                $this->revisions->projectToRequest($locked, $revision->fresh());

                $this->auditLog->logSystem('directory.reset.success', 'password_reset_request', (string) $locked->id, [
                    'student_id' => $locked->student_id,
                    'directory' => $directoryKey,
                    'revision_number' => $revision->revision_number,
                ]);

                if ($attempts >= 1) {
                    $this->auditLog->logSystem('directory.retry.completed', 'password_reset_request', (string) $locked->id, [
                        'directory' => $directoryKey,
                        'revision_number' => $revision->revision_number,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            $reason = 'unexpected_error';
            $retryMode = DirectoryRetryMode::None;

            if ($exception instanceof DirectoryResetException) {
                $reason = $exception->reason;
                $retryMode = $exception->retryMode;
            }

            DB::transaction(function () use ($requestId, $revisionId, $directoryKey, $reason, $retryMode): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->find($requestId);
                $revision = PasswordResetRevision::query()->lockForUpdate()->find($revisionId);
                if ($locked === null || $revision === null) {
                    return;
                }

                if (($revision->directory_results['results'][$directoryKey]['status'] ?? null) !== 'processing') {
                    return;
                }

                $this->recordFailure($revision, $directoryKey, $reason, $retryMode);
                $this->recalculateStatusAndRetry($locked, $revision);
                $this->syncLegacyGoogleColumns($locked, $revision);
                $revision->save();
                $locked->save();
                $this->revisions->projectToRequest($locked, $revision);

                $this->auditLog->logSystem('directory.reset.failed', 'password_reset_request', (string) $locked->id, [
                    'student_id' => $locked->student_id,
                    'directory' => $directoryKey,
                    'reason' => $reason,
                    'retry_mode' => $retryMode->value,
                    'revision_number' => $revision->revision_number,
                ]);

                $attempts = (int) ($revision->directory_results['results'][$directoryKey]['attempts'] ?? 0);
                if ($attempts > 1) {
                    $this->auditLog->logSystem('directory.retry.failed', 'password_reset_request', (string) $locked->id, [
                        'directory' => $directoryKey,
                        'reason' => $reason,
                        'revision_number' => $revision->revision_number,
                    ]);
                }
            });

            Log::error('Directory password reset failed.', [
                'request_id' => $requestId,
                'directory' => $directoryKey,
                'reason' => $reason,
            ]);
        }

        unset($plainPassword);

        $this->notifySlack($requestId);
    }

    private function notifySlack(int $requestId): void
    {
        $request = PasswordResetRequest::query()->find($requestId);

        if ($request === null || $request->slack_channel_id === null || $request->slack_message_ts === null) {
            return;
        }

        try {
            $this->slackApproval->appendDirectoryResetStatus($request);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update Slack message after directory reset.', [
                'request_id' => $requestId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function buildOutcome(int $requestId): DirectoryResetOutcome
    {
        $request = PasswordResetRequest::query()->find($requestId);
        $revision = $request?->activeRevision()->first();

        if ($revision === null) {
            return new DirectoryResetOutcome(false);
        }

        return new DirectoryResetOutcome($this->hasAutomaticRetryableFailures($revision));
    }

    private function isEligible(PasswordResetRequest $request): bool
    {
        return in_array($request->status, [
            PasswordResetRequestStatus::ApprovedProcessing,
            PasswordResetRequestStatus::PartiallyCompleted,
        ], true);
    }

    private function expirePendingCredential(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $this->pendingPasswords->deleteRevision($revision, 'expiration');
        $revision->forceFill([
            'retry_available' => false,
            'status' => PasswordResetRevisionStatus::Failed,
        ])->save();
        $request->forceFill([
            'retry_available' => false,
            'status' => PasswordResetRequestStatus::Failed,
        ])->save();
        $this->revisions->projectToRequest($request, $revision);

        $this->auditLog->logSystem('password_revision.expired', 'password_reset_request', (string) $request->id, [
            'student_id' => $request->student_id,
            'revision_number' => $revision->revision_number,
        ]);
    }

    private function ensureDirectorySnapshot(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $existing = $revision->directory_results;

        if (is_array($existing)
            && isset($existing['planned_directories'], $existing['required_directories'], $existing['results'])
        ) {
            return;
        }

        $planned = [];
        $required = [];
        $results = [];

        foreach ($this->orderedResetters() as $resetter) {
            $key = $resetter->key();
            $planned[] = $key;

            if ($key === 'active_directory' && ! config('active-directory.enabled')) {
                $results[$key] = $this->emptyResult('skipped', 'disabled');
                continue;
            }

            if (! $resetter->isConfigured()) {
                $results[$key] = $this->emptyResult('skipped', 'not_configured');
                continue;
            }

            if ($request->student !== null && ! $resetter->supports($request->student)) {
                $results[$key] = $this->emptyResult('skipped', 'unsupported');
                continue;
            }

            $required[] = $key;
            $results[$key] = $this->emptyResult('pending');
        }

        $revision->directory_results = [
            'planned_directories' => $planned,
            'required_directories' => $required,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $status, ?string $reason = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'retry_mode' => DirectoryRetryMode::None->value,
            'attempts' => 0,
            'last_attempt_at' => null,
            'processing_started_at' => null,
            'completed_at' => null,
        ];
    }

    private function reclaimStaleProcessing(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $minutes = (int) config('directory-processing.stale_processing_minutes', 5);
        $results = $revision->directory_results ?? [];
        $changed = false;

        foreach ($results['results'] ?? [] as $key => $result) {
            if (($result['status'] ?? null) !== 'processing') {
                continue;
            }

            $started = $result['processing_started_at'] ?? null;
            if ($started === null) {
                continue;
            }

            if (\Carbon\Carbon::parse($started)->lessThanOrEqualTo(now()->subMinutes($minutes))) {
                $results['results'][$key]['status'] = 'pending';
                $results['results'][$key]['processing_started_at'] = null;
                $changed = true;

                $this->auditLog->logSystem('directory.processing.reclaimed', 'password_reset_request', (string) $request->id, [
                    'directory' => $key,
                    'previous_processing_started_at' => $started,
                    'revision_number' => $revision->revision_number,
                ]);
            }
        }

        if ($changed) {
            $revision->directory_results = $results;
        }
    }

    private function selectNextDirectoryKey(PasswordResetRevision $revision): ?string
    {
        $results = $revision->directory_results['results'] ?? [];
        $required = $revision->directory_results['required_directories'] ?? [];

        foreach ($required as $key) {
            if (isset($this->attemptedThisRun[$key])) {
                continue;
            }

            $status = $results[$key]['status'] ?? null;
            $retryMode = $results[$key]['retry_mode'] ?? DirectoryRetryMode::None->value;

            if ($status === 'success' || $status === 'processing' || $status === 'skipped') {
                continue;
            }

            if ($status === 'pending'
                || ($status === 'failed' && in_array($retryMode, [
                    DirectoryRetryMode::Automatic->value,
                    DirectoryRetryMode::Manual->value,
                ], true))
            ) {
                $this->attemptedThisRun[$key] = true;

                return $key;
            }
        }

        return null;
    }

    private function recordFailure(
        PasswordResetRevision $revision,
        string $directoryKey,
        string $reason,
        DirectoryRetryMode $retryMode,
    ): void {
        $results = $revision->directory_results ?? [];
        $attempts = (int) ($results['results'][$directoryKey]['attempts'] ?? 0);
        $results['results'][$directoryKey] = array_merge($results['results'][$directoryKey] ?? [], [
            'status' => 'failed',
            'reason' => $reason,
            'retry_mode' => $retryMode->value,
            'attempts' => $attempts + 1,
            'last_attempt_at' => now()->toIso8601String(),
            'processing_started_at' => null,
            'completed_at' => null,
        ]);
        $revision->directory_results = $results;
    }

    private function recalculateStatusAndRetry(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $required = $revision->directory_results['required_directories'] ?? [];
        $results = $revision->directory_results['results'] ?? [];

        if ($required === []) {
            $request->status = PasswordResetRequestStatus::Failed;
            $revision->retry_available = false;
            $request->retry_available = false;

            return;
        }

        $successCount = 0;
        $pendingOrProcessing = 0;
        $automaticFailures = 0;
        $terminalFailures = 0;

        foreach ($required as $key) {
            $status = $results[$key]['status'] ?? 'pending';
            $retryMode = $results[$key]['retry_mode'] ?? DirectoryRetryMode::None->value;

            if ($status === 'success') {
                $successCount++;
            } elseif (in_array($status, ['pending', 'processing'], true)) {
                $pendingOrProcessing++;
            } elseif ($status === 'failed') {
                if ($retryMode === DirectoryRetryMode::Automatic->value) {
                    $automaticFailures++;
                } else {
                    $terminalFailures++;
                }
            }
        }

        $requiredCount = count($required);

        if ($successCount === $requiredCount) {
            $request->status = PasswordResetRequestStatus::Completed;
        } elseif ($successCount > 0 && ($terminalFailures + $automaticFailures + $pendingOrProcessing) > 0) {
            $request->status = PasswordResetRequestStatus::PartiallyCompleted;
        } elseif ($successCount === 0 && $automaticFailures > 0) {
            $request->status = PasswordResetRequestStatus::ApprovedProcessing;
        } elseif ($successCount === 0 && $pendingOrProcessing > 0) {
            $request->status = PasswordResetRequestStatus::ApprovedProcessing;
        } elseif ($successCount === 0 && $terminalFailures === $requiredCount) {
            $request->status = PasswordResetRequestStatus::Failed;
            $revision->status = PasswordResetRevisionStatus::Failed;
        } else {
            $request->status = PasswordResetRequestStatus::PartiallyCompleted;
        }

        $hasRetryableDirectory = false;
        foreach ($required as $key) {
            if (($results[$key]['status'] ?? null) !== 'failed') {
                continue;
            }
            $mode = $results[$key]['retry_mode'] ?? DirectoryRetryMode::None->value;
            if (in_array($mode, [DirectoryRetryMode::Automatic->value, DirectoryRetryMode::Manual->value], true)) {
                $hasRetryableDirectory = true;
                break;
            }
        }

        $revision->retry_available = $hasRetryableDirectory
            && $revision->hasEncryptedPendingPassword()
            && ! $revision->pendingPasswordExpired();
        $request->retry_available = $revision->retry_available;
    }

    private function hasAutomaticRetryableFailures(PasswordResetRevision $revision): bool
    {
        $required = $revision->directory_results['required_directories'] ?? [];
        $results = $revision->directory_results['results'] ?? [];

        foreach ($required as $key) {
            if (($results[$key]['status'] ?? null) !== 'failed') {
                continue;
            }
            if (($results[$key]['retry_mode'] ?? null) === DirectoryRetryMode::Automatic->value) {
                return true;
            }
        }

        return false;
    }

    private function syncLegacyGoogleColumns(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $google = $revision->directory_results['results']['google'] ?? null;

        if (! is_array($google)) {
            return;
        }

        $status = $google['status'] ?? null;

        if (in_array($status, ['success', 'failed'], true)) {
            $request->google_reset_attempted_at = isset($google['last_attempt_at'])
                ? \Carbon\Carbon::parse($google['last_attempt_at'])
                : ($request->google_reset_attempted_at ?? now());
        }

        if ($status === 'success') {
            $request->google_reset_success = true;
            $request->google_error_message = null;
        } elseif ($status === 'failed') {
            $request->google_reset_success = false;
            $request->google_error_message = 'Google password reset failed.';
        }
    }

    private function resetterByKey(string $key): ?DirectoryPasswordResetter
    {
        foreach ($this->orderedResetters() as $resetter) {
            if ($resetter->key() === $key) {
                return $resetter;
            }
        }

        return null;
    }

    /**
     * @return list<DirectoryPasswordResetter>
     */
    private function orderedResetters(): array
    {
        return array_values(iterator_to_array($this->directoryResetters, false));
    }
}

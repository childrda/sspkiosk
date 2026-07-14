<?php

namespace App\Services;

use App\Contracts\DirectoryPasswordResetter;
use App\Enums\DirectoryRetryMode;
use App\Enums\PasswordResetRequestStatus;
use App\Exceptions\DirectoryResetException;
use App\Models\PasswordResetRequest;
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
    ) {}

    public function process(int $passwordResetRequestId): DirectoryResetOutcome
    {
        $this->attemptedThisRun = [];

        $claimed = $this->claimNextDirectory($passwordResetRequestId);

        while ($claimed !== null) {
            $this->executeClaim($claimed['request_id'], $claimed['directory_key'], $claimed['force_change']);
            $claimed = $this->claimNextDirectory($passwordResetRequestId);
        }

        return $this->buildOutcome($passwordResetRequestId);
    }

    /**
     * @return array{request_id: int, directory_key: string, force_change: bool}|null
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

            if ($this->pendingPasswordExpired($request)) {
                $this->expirePendingCredential($request);

                return null;
            }

            if (! $this->pendingPasswords->hasEncryptedPendingPassword($request)) {
                $request->forceFill([
                    'status' => PasswordResetRequestStatus::Failed,
                    'retry_available' => false,
                    'google_reset_attempted_at' => $request->google_reset_attempted_at ?? now(),
                    'google_reset_success' => false,
                    'google_error_message' => 'Pending password unavailable.',
                ])->save();

                return null;
            }

            $this->ensureDirectorySnapshot($request);
            $this->reclaimStaleProcessing($request);

            $key = $this->selectNextDirectoryKey($request);

            if ($key === null) {
                $this->recalculateStatusAndRetry($request);
                $this->syncLegacyGoogleColumns($request);
                $request->save();

                return null;
            }

            $results = $request->directory_results ?? [];
            $results['results'][$key]['status'] = 'processing';
            $results['results'][$key]['processing_started_at'] = now()->toIso8601String();
            $request->directory_results = $results;
            $request->save();

            return [
                'request_id' => $request->id,
                'directory_key' => $key,
                'force_change' => (bool) $request->force_change_at_next_login,
            ];
        });
    }

    private function executeClaim(int $requestId, string $directoryKey, bool $forceChange): void
    {
        $request = PasswordResetRequest::query()->with('student')->find($requestId);

        if ($request === null) {
            return;
        }

        $plainPassword = $this->pendingPasswords->decrypt($request);

        if ($plainPassword === null) {
            DB::transaction(function () use ($requestId, $directoryKey): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->find($requestId);
                if ($locked === null) {
                    return;
                }
                $this->recordFailure(
                    $locked,
                    $directoryKey,
                    'unexpected_error',
                    DirectoryRetryMode::None,
                );
                $this->recalculateStatusAndRetry($locked);
                $this->syncLegacyGoogleColumns($locked);
                $locked->save();
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

            DB::transaction(function () use ($requestId, $directoryKey): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->with('student')->find($requestId);
                if ($locked === null) {
                    return;
                }

                $results = $locked->directory_results ?? [];
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
                $locked->directory_results = $results;
                $this->recalculateStatusAndRetry($locked);
                $this->syncLegacyGoogleColumns($locked);

                if ($locked->status === PasswordResetRequestStatus::Completed) {
                    $this->pendingPasswords->delete($locked, 'approval');
                }

                $locked->save();

                $this->auditLog->logSystem('directory.reset.success', 'password_reset_request', (string) $locked->id, [
                    'student_id' => $locked->student_id,
                    'directory' => $directoryKey,
                ]);
            });
        } catch (\Throwable $exception) {
            $reason = 'unexpected_error';
            $retryMode = DirectoryRetryMode::None;

            if ($exception instanceof DirectoryResetException) {
                $reason = $exception->reason;
                $retryMode = $exception->retryMode;
            }

            DB::transaction(function () use ($requestId, $directoryKey, $reason, $retryMode): void {
                $locked = PasswordResetRequest::query()->lockForUpdate()->find($requestId);
                if ($locked === null) {
                    return;
                }

                if (($locked->directory_results['results'][$directoryKey]['status'] ?? null) !== 'processing') {
                    return;
                }

                $this->recordFailure($locked, $directoryKey, $reason, $retryMode);
                $this->recalculateStatusAndRetry($locked);
                $this->syncLegacyGoogleColumns($locked);
                $locked->save();

                $this->auditLog->logSystem('directory.reset.failed', 'password_reset_request', (string) $locked->id, [
                    'student_id' => $locked->student_id,
                    'directory' => $directoryKey,
                    'reason' => $reason,
                    'retry_mode' => $retryMode->value,
                ]);
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

        if ($request === null) {
            return new DirectoryResetOutcome(false);
        }

        return new DirectoryResetOutcome($this->hasAutomaticRetryableFailures($request));
    }

    private function isEligible(PasswordResetRequest $request): bool
    {
        return in_array($request->status, [
            PasswordResetRequestStatus::ApprovedProcessing,
            PasswordResetRequestStatus::PartiallyCompleted,
        ], true);
    }

    private function pendingPasswordExpired(PasswordResetRequest $request): bool
    {
        return $request->pending_password_expires_at !== null
            && $request->pending_password_expires_at->isPast();
    }

    private function expirePendingCredential(PasswordResetRequest $request): void
    {
        $this->pendingPasswords->delete($request, 'expiration');
        $request->forceFill([
            'retry_available' => false,
            'status' => PasswordResetRequestStatus::Failed,
        ])->save();

        $this->auditLog->logSystem('password_revision.expired', 'password_reset_request', (string) $request->id, [
            'student_id' => $request->student_id,
        ]);
    }

    private function ensureDirectorySnapshot(PasswordResetRequest $request): void
    {
        $existing = $request->directory_results;

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

        $request->directory_results = [
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

    private function reclaimStaleProcessing(PasswordResetRequest $request): void
    {
        $minutes = (int) config('directory-processing.stale_processing_minutes', 5);
        $results = $request->directory_results ?? [];
        $changed = false;

        foreach ($results['results'] ?? [] as $key => $result) {
            if (($result['status'] ?? null) !== 'processing') {
                continue;
            }

            $started = $result['processing_started_at'] ?? null;
            if ($started === null) {
                continue;
            }

            if (now()->diffInMinutes(\Carbon\Carbon::parse($started), false) > -$minutes
                && \Carbon\Carbon::parse($started)->greaterThan(now()->subMinutes($minutes))
            ) {
                continue;
            }

            if (\Carbon\Carbon::parse($started)->lessThanOrEqualTo(now()->subMinutes($minutes))) {
                $results['results'][$key]['status'] = 'pending';
                $results['results'][$key]['processing_started_at'] = null;
                $changed = true;

                $this->auditLog->logSystem('directory.processing.reclaimed', 'password_reset_request', (string) $request->id, [
                    'directory' => $key,
                    'previous_processing_started_at' => $started,
                ]);
            }
        }

        if ($changed) {
            $request->directory_results = $results;
        }
    }

    private function selectNextDirectoryKey(PasswordResetRequest $request): ?string
    {
        $results = $request->directory_results['results'] ?? [];
        $required = $request->directory_results['required_directories'] ?? [];

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
                || ($status === 'failed' && $retryMode === DirectoryRetryMode::Automatic->value)
            ) {
                $this->attemptedThisRun[$key] = true;

                return $key;
            }
        }

        return null;
    }

    private function recordFailure(
        PasswordResetRequest $request,
        string $directoryKey,
        string $reason,
        DirectoryRetryMode $retryMode,
    ): void {
        $results = $request->directory_results ?? [];
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
        $request->directory_results = $results;
    }

    private function recalculateStatusAndRetry(PasswordResetRequest $request): void
    {
        $required = $request->directory_results['required_directories'] ?? [];
        $results = $request->directory_results['results'] ?? [];

        if ($required === []) {
            $request->status = PasswordResetRequestStatus::Failed;
            $request->retry_available = false;

            return;
        }

        $successCount = 0;
        $pendingOrProcessing = 0;
        $automaticFailures = 0;
        $terminalFailures = 0;
        $manualFailures = 0;

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
                } elseif ($retryMode === DirectoryRetryMode::Manual->value) {
                    $manualFailures++;
                    $terminalFailures++;
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

        $request->retry_available = $hasRetryableDirectory
            && $this->pendingPasswords->hasEncryptedPendingPassword($request)
            && ! $this->pendingPasswordExpired($request);
    }

    private function hasAutomaticRetryableFailures(PasswordResetRequest $request): bool
    {
        $required = $request->directory_results['required_directories'] ?? [];
        $results = $request->directory_results['results'] ?? [];

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

    private function syncLegacyGoogleColumns(PasswordResetRequest $request): void
    {
        $google = $request->directory_results['results']['google'] ?? null;

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

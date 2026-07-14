<?php

namespace App\Services;

use App\Enums\PasswordOrigin;
use App\Enums\PasswordResetRequestStatus;
use App\Enums\PasswordResetRevisionStatus;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\PasswordResetRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PasswordRevisionService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly PendingPasswordService $pendingPasswords,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runTransactionally(callable $callback)
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }

    public function projectToRequest(PasswordResetRequest $request, PasswordResetRevision $revision): void
    {
        $request->forceFill([
            'password_mode' => $revision->password_mode,
            'password_origin' => $revision->password_origin,
            'force_change_at_next_login' => $revision->force_change_at_next_login,
            'retry_available' => $revision->retry_available,
            'directory_results' => $revision->directory_results,
            'encrypted_pending_password' => $revision->encrypted_pending_password,
            'pending_password_expires_at' => $revision->pending_password_expires_at,
            'pending_password_created_at' => $revision->pending_password_created_at,
            'pending_password_displayed_at' => $revision->pending_password_displayed_at,
            'pending_password_printed_at' => $revision->pending_password_printed_at,
            'pending_password_deleted_at' => $revision->pending_password_deleted_at,
            'pending_password_type' => $revision->pending_password_type,
        ])->save();
    }

    public function clearLegacyGoogleProjection(PasswordResetRequest $request): void
    {
        $request->forceFill([
            'google_reset_attempted_at' => null,
            'google_reset_success' => null,
            'google_error_message' => null,
        ])->save();
    }

    public function activeRevision(PasswordResetRequest $request): ?PasswordResetRevision
    {
        return PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->whereNull('superseded_at')
            ->where('status', PasswordResetRevisionStatus::Active)
            ->whereNotNull('active_for_request_id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ensure an active revision exists (creates revision 1 when storing the first credential).
     */
    public function ensureActiveRevisionForStore(PasswordResetRequest $request): PasswordResetRevision
    {
        $active = $this->activeRevision($request);

        if ($active !== null) {
            return $active;
        }

        return PasswordResetRevision::query()->create([
            'password_reset_request_id' => $request->id,
            'revision_number' => 1,
            'status' => PasswordResetRevisionStatus::Active,
            'active_for_request_id' => $request->id,
            'retry_available' => false,
        ]);
    }

    public function retryFailedDirectories(PasswordResetRequest $request, User $admin): void
    {
        $this->runTransactionally(function () use ($request, $admin): void {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);
            $revision = $this->activeRevision($locked);

            if ($revision === null) {
                throw new ConflictHttpException('No active password revision is available to retry.');
            }

            if (! $revision->retry_available || ! $revision->hasEncryptedPendingPassword()) {
                throw new ConflictHttpException('This revision is not eligible for directory retry.');
            }

            if ($revision->pendingPasswordExpired()) {
                throw new ConflictHttpException('The pending password has expired and cannot be retried.');
            }

            if (! $this->hasRetryableDirectoryFailure($revision)) {
                throw new ConflictHttpException('No retryable directory failures remain. Use password replacement instead.');
            }

            if (! in_array($locked->status, [
                PasswordResetRequestStatus::PartiallyCompleted,
                PasswordResetRequestStatus::ApprovedProcessing,
                PasswordResetRequestStatus::Failed,
            ], true)) {
                throw new ConflictHttpException('This request cannot be retried in its current status.');
            }

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::ApprovedProcessing,
            ])->save();

            $this->projectToRequest($locked, $revision);

            $this->auditLog->logAdmin(
                'directory.retry.requested',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
                ['revision_number' => $revision->revision_number],
            );
        });

        ResetDirectoryPasswordsJob::dispatch($request->id);
    }

    public function startPasswordReplacement(PasswordResetRequest $request, User $admin, string $reason, string $confirmation): PasswordResetRevision
    {
        if (trim($confirmation) !== 'REPLACE PASSWORD') {
            throw new ConflictHttpException('Type REPLACE PASSWORD to confirm replacement.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new ConflictHttpException('A replacement reason is required.');
        }

        return $this->runTransactionally(function () use ($request, $admin, $reason): PasswordResetRevision {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);
            $previous = $this->activeRevision($locked);

            if ($previous === null) {
                throw new ConflictHttpException('No active revision is available to replace.');
            }

            if ($locked->status !== PasswordResetRequestStatus::PartiallyCompleted
                && $locked->status !== PasswordResetRequestStatus::Failed
            ) {
                throw new ConflictHttpException('Password replacement is only available for partial or failed directory results.');
            }

            $replacementCount = PasswordResetRevision::query()
                ->where('password_reset_request_id', $locked->id)
                ->where('revision_number', '>', 1)
                ->count();

            $max = (int) config('student-password-reset.max_replacement_revisions', 3);
            if ($replacementCount >= $max) {
                throw new ConflictHttpException(
                    "Replacement limit reached ({$max}). Manual directory reconciliation is required."
                );
            }

            $this->supersedeRevision($previous);

            $planned = $previous->directory_results['planned_directories'] ?? ['google', 'active_directory'];
            $required = $previous->directory_results['required_directories'] ?? ['google', 'active_directory'];
            $results = [];
            foreach ($planned as $key) {
                $results[$key] = [
                    'status' => in_array($key, $required, true) ? 'pending' : 'skipped',
                    'reason' => in_array($key, $required, true) ? null : ($previous->directory_results['results'][$key]['reason'] ?? 'disabled'),
                    'retry_mode' => 'none',
                    'attempts' => 0,
                    'last_attempt_at' => null,
                    'processing_started_at' => null,
                    'completed_at' => null,
                ];
            }

            $new = PasswordResetRevision::query()->create([
                'password_reset_request_id' => $locked->id,
                'revision_number' => $previous->revision_number + 1,
                'password_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
                'password_origin' => PasswordOrigin::StudentSelected->value,
                'force_change_at_next_login' => false,
                'pending_password_type' => PendingPasswordType::StudentSelected->value,
                'directory_results' => [
                    'planned_directories' => $planned,
                    'required_directories' => $required,
                    'results' => $results,
                ],
                'retry_available' => false,
                'status' => PasswordResetRevisionStatus::Active,
                'active_for_request_id' => $locked->id,
                'pending_password_expires_at' => $locked->expires_at,
            ]);

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::AwaitingPasswordReselection,
                'superseded_student_selected_password' => true,
            ])->save();

            $this->projectToRequest($locked, $new);

            $this->auditLog->logAdmin(
                'admin.request.password_replacement_started',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
                [
                    'previous_revision_number' => $previous->revision_number,
                    'new_revision_number' => $new->revision_number,
                    'reason' => $reason,
                ],
            );

            $this->auditLog->logSystem(
                'password_revision.created',
                'password_reset_request',
                (string) $locked->id,
                ['revision_number' => $new->revision_number],
            );

            return $new;
        });
    }

    public function storeReselectionPassword(PasswordResetRequest $request, string $plainPassword): PasswordResetRevision
    {
        return $this->runTransactionally(function () use ($request, $plainPassword): PasswordResetRevision {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== PasswordResetRequestStatus::AwaitingPasswordReselection) {
                throw new ConflictHttpException('This request is not awaiting password re-selection.');
            }

            $revision = $this->activeRevision($locked);
            if ($revision === null) {
                throw new ConflictHttpException('No active replacement revision found.');
            }

            $this->pendingPasswords->storeOnRevision(
                $revision,
                $plainPassword,
                PendingPasswordType::StudentSelected,
                PasswordOrigin::StudentSelected,
                false,
                ResetPasswordMode::StudentSelectedPendingApproval->value,
            );

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::Pending,
                'expires_at' => now()->addMinutes((int) config('student-password-reset.reset_request_expiration_minutes')),
            ])->save();

            $revision->refresh();
            $revision->forceFill([
                'pending_password_expires_at' => $locked->expires_at,
            ])->save();

            $this->projectToRequest($locked, $revision->fresh());

            $this->auditLog->logStudent(
                'student.password_reselection.submitted',
                $locked->student_id,
                [
                    'request_id' => $locked->id,
                    'revision_number' => $revision->revision_number,
                ],
            );

            return $revision->fresh();
        });
    }

    public function cancel(PasswordResetRequest $request, User $admin, string $reason, string $confirmation): void
    {
        if (trim($confirmation) !== 'CANCEL REQUEST') {
            throw new ConflictHttpException('Type CANCEL REQUEST to confirm cancellation.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new ConflictHttpException('A cancellation reason is required.');
        }

        $this->runTransactionally(function () use ($request, $admin, $reason): void {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);
            $revision = $this->activeRevision($locked);

            if (in_array($locked->status, [
                PasswordResetRequestStatus::Completed,
                PasswordResetRequestStatus::Denied,
                PasswordResetRequestStatus::Expired,
            ], true)) {
                throw new ConflictHttpException('This request is already terminal.');
            }

            if ($revision !== null) {
                $revision->forceFill([
                    'encrypted_pending_password' => null,
                    'pending_password_deleted_at' => now(),
                    'retry_available' => false,
                    'status' => PasswordResetRevisionStatus::Cancelled,
                    'superseded_at' => now(),
                    'active_for_request_id' => null,
                ])->save();
                $this->projectToRequest($locked, $revision);
            }

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::Denied,
                'denied_at' => now(),
                'denial_reason' => $reason,
                'retry_available' => false,
            ])->save();

            $this->auditLog->logAdmin(
                'admin.request.cancelled',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
                [
                    'reason' => $reason,
                    'revision_number' => $revision?->revision_number,
                ],
            );
        });
    }

    public function createOfficeGeneratedRevision(
        PasswordResetRequest $request,
        string $plainPassword,
        bool $supersededStudentSelected,
    ): PasswordResetRevision {
        return $this->runTransactionally(function () use ($request, $plainPassword, $supersededStudentSelected): PasswordResetRevision {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);
            $previous = $this->activeRevision($locked);

            if ($previous !== null) {
                $this->supersedeRevision($previous);
                $nextNumber = $previous->revision_number + 1;
                $planned = $previous->directory_results['planned_directories'] ?? null;
                $required = $previous->directory_results['required_directories'] ?? null;
            } else {
                $nextNumber = ((int) PasswordResetRevision::query()
                    ->where('password_reset_request_id', $locked->id)
                    ->max('revision_number')) + 1;
                if ($nextNumber < 1) {
                    $nextNumber = 1;
                }
                $planned = null;
                $required = null;
            }

            $results = null;
            if ($planned !== null && $required !== null) {
                $mapped = [];
                foreach ($planned as $key) {
                    $mapped[$key] = [
                        'status' => in_array($key, $required, true) ? 'pending' : 'skipped',
                        'reason' => in_array($key, $required, true) ? null : 'disabled',
                        'retry_mode' => 'none',
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'processing_started_at' => null,
                        'completed_at' => null,
                    ];
                }
                $results = [
                    'planned_directories' => $planned,
                    'required_directories' => $required,
                    'results' => $mapped,
                ];
            }

            $revision = PasswordResetRevision::query()->create([
                'password_reset_request_id' => $locked->id,
                'revision_number' => max(1, $nextNumber),
                'status' => PasswordResetRevisionStatus::Active,
                'active_for_request_id' => $locked->id,
                'directory_results' => $results,
                'retry_available' => false,
            ]);

            $this->pendingPasswords->storeOnRevision(
                $revision,
                $plainPassword,
                PendingPasswordType::TemporaryGenerated,
                PasswordOrigin::OfficeGeneratedTemporary,
                true,
                $locked->reset_mode,
            );

            if ($supersededStudentSelected) {
                $locked->forceFill(['superseded_student_selected_password' => true])->save();
            }

            $this->clearLegacyGoogleProjection($locked->fresh());
            $this->projectToRequest($locked->fresh(), $revision->fresh());

            $this->auditLog->logSystem('password_revision.created', 'password_reset_request', (string) $locked->id, [
                'revision_number' => $revision->revision_number,
                'password_origin' => PasswordOrigin::OfficeGeneratedTemporary->value,
            ]);

            return $revision->fresh();
        });
    }

    public function supersedeRevision(PasswordResetRevision $revision): void
    {
        $revision->forceFill([
            'encrypted_pending_password' => null,
            'pending_password_deleted_at' => now(),
            'retry_available' => false,
            'status' => PasswordResetRevisionStatus::Superseded,
            'superseded_at' => now(),
            'active_for_request_id' => null,
        ])->save();

        $this->auditLog->logSystem('password_revision.superseded', 'password_reset_request', (string) $revision->password_reset_request_id, [
            'revision_number' => $revision->revision_number,
        ]);
    }

    public function hasRetryableDirectoryFailure(PasswordResetRevision $revision): bool
    {
        $required = $revision->directory_results['required_directories'] ?? [];
        $results = $revision->directory_results['results'] ?? [];

        foreach ($required as $key) {
            if (($results[$key]['status'] ?? null) !== 'failed') {
                continue;
            }
            $mode = $results[$key]['retry_mode'] ?? 'none';
            if (in_array($mode, ['automatic', 'manual'], true)) {
                return true;
            }
        }

        return false;
    }

    public function hasOnlyNoneRetryFailures(PasswordResetRevision $revision): bool
    {
        $required = $revision->directory_results['required_directories'] ?? [];
        $results = $revision->directory_results['results'] ?? [];
        $failed = false;

        foreach ($required as $key) {
            if (($results[$key]['status'] ?? null) !== 'failed') {
                continue;
            }
            $failed = true;
            if (($results[$key]['retry_mode'] ?? 'none') !== 'none') {
                return false;
            }
        }

        return $failed;
    }
}

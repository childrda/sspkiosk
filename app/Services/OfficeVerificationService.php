<?php

namespace App\Services;

use App\Enums\PasswordOrigin;
use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OfficeVerificationService
{
    public function __construct(
        private readonly PasswordGeneratorService $passwords,
        private readonly PendingPasswordService $pendingPasswords,
        private readonly AuditLogService $auditLog,
        private readonly SlackApprovalService $slackApproval,
    ) {}

    public function verify(PasswordResetRequest $request, User $admin, ?string $notes): string
    {
        $this->assertQueueHealthy();

        return $this->runTransactionally(function () use ($request, $admin, $notes): string {
            $locked = PasswordResetRequest::query()
                ->lockForUpdate()
                ->find($request->id);

            if (! $locked) {
                throw new ConflictHttpException('Reset request not found.');
            }

            $this->assertNeedsOfficeVerification($locked);
            $this->assertOfficeVerificationNotExpired($locked);

            $plain = $this->passwords->generate();
            $superseded = $locked->password_origin === PasswordOrigin::StudentSelected->value
                || $locked->pending_password_type === PendingPasswordType::StudentSelected->value;

            $this->pendingPasswords->store(
                $locked,
                $plain,
                PendingPasswordType::TemporaryGenerated,
                PasswordOrigin::OfficeGeneratedTemporary,
                true,
                $superseded,
                $locked->reset_mode,
            );

            $locked->forceFill([
                'office_verified_at' => now(),
                'office_verified_by_user_id' => $admin->id,
                'office_verification_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'status' => PasswordResetRequestStatus::ApprovedProcessing,
            ])->save();

            $this->auditLog->logAdmin(
                'admin.request.office_verified',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
                ['notes_supplied' => $notes !== null && trim($notes) !== ''],
            );

            ResetDirectoryPasswordsJob::dispatch($locked->id);

            $locked->refresh();
            $this->slackApproval->notifyOfficeOutcome($locked, 'Verified in office — directory reset queued', $admin);

            return $plain;
        });
    }

    public function reject(PasswordResetRequest $request, User $admin, string $reason): void
    {
        $this->runTransactionally(function () use ($request, $admin, $reason): void {
            $locked = PasswordResetRequest::query()
                ->lockForUpdate()
                ->find($request->id);

            if (! $locked) {
                throw new ConflictHttpException('Reset request not found.');
            }

            $this->assertNeedsOfficeVerification($locked);

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::Denied,
                'denied_at' => now(),
                'denial_reason' => trim($reason),
                'denied_by_slack_user_id' => null,
            ])->save();

            $this->pendingPasswords->delete($locked, 'denial');

            $this->auditLog->logAdmin(
                'admin.request.office_rejected',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
                ['reason_supplied' => true],
            );

            $locked->refresh();
            $this->slackApproval->notifyOfficeOutcome($locked, 'Rejected in office', $admin);
        });
    }

    public function retry(PasswordResetRequest $request, User $admin): string
    {
        $this->assertQueueHealthy();

        return $this->runTransactionally(function () use ($request, $admin): string {
            $locked = PasswordResetRequest::query()
                ->lockForUpdate()
                ->find($request->id);

            if (! $locked) {
                throw new ConflictHttpException('Reset request not found.');
            }

            if ($locked->status !== PasswordResetRequestStatus::Failed) {
                throw new ConflictHttpException('Only failed reset requests can be retried.');
            }

            $plain = $this->passwords->generate();

            $this->pendingPasswords->store(
                $locked,
                $plain,
                PendingPasswordType::TemporaryGenerated,
                PasswordOrigin::OfficeGeneratedTemporary,
                true,
                false,
                $locked->reset_mode,
            );

            $locked->forceFill([
                'status' => PasswordResetRequestStatus::ApprovedProcessing,
            ])->save();

            $this->auditLog->logAdmin(
                'admin.request.reset_retried',
                $admin->id,
                'password_reset_request',
                (string) $locked->id,
            );

            ResetDirectoryPasswordsJob::dispatch($locked->id);

            return $plain;
        });
    }

    private function assertNeedsOfficeVerification(PasswordResetRequest $request): void
    {
        if ($request->status !== PasswordResetRequestStatus::NeedsOfficeVerification) {
            throw new ConflictHttpException('This request is not awaiting office verification.');
        }
    }

    private function assertOfficeVerificationNotExpired(PasswordResetRequest $request): void
    {
        if ($request->office_verification_expires_at !== null
            && $request->office_verification_expires_at->isPast()) {
            throw new ConflictHttpException(
                'This office verification window has expired. Ask the student to submit a new reset request at the kiosk.',
            );
        }
    }

    private function assertQueueHealthy(): void
    {
        $depth = DB::table('jobs')->count();
        $maxDepth = (int) config('student-password-reset.office_verification_max_queue_depth');

        if ($depth > $maxDepth) {
            throw new ConflictHttpException(
                "The queue has {$depth} pending jobs (limit {$maxDepth}). The queue worker may be down — start sspkiosk-queue before verifying.",
            );
        }
    }

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
}

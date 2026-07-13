<?php

namespace App\Jobs;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Exceptions\GoogleWorkspaceException;
use App\Models\PasswordResetRequest;
use App\Services\AuditLogService;
use App\Services\GoogleWorkspaceDirectoryService;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetGooglePasswordJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $passwordResetRequestId,
    ) {}

    public function handle(
        GoogleWorkspaceDirectoryService $directoryService,
        PendingPasswordService $pendingPasswords,
        AuditLogService $auditLog,
        SlackApprovalService $slackApproval,
    ): void {
        $this->runTransactionally(function () use (
            $directoryService,
            $pendingPasswords,
            $auditLog,
            $slackApproval,
        ): void {
            $request = PasswordResetRequest::query()
                ->lockForUpdate()
                ->with(['student', 'kiosk'])
                ->find($this->passwordResetRequestId);

            if (! $request) {
                return;
            }

            if ($request->status !== PasswordResetRequestStatus::ApprovedProcessing) {
                return;
            }

            if ($request->google_reset_attempted_at !== null) {
                return;
            }

            $plainPassword = $pendingPasswords->decrypt($request);

            if ($plainPassword === null) {
                $request->update([
                    'status' => PasswordResetRequestStatus::Denied,
                    'google_reset_attempted_at' => now(),
                    'google_reset_success' => false,
                    'google_error_message' => 'Pending password unavailable.',
                ]);

                return;
            }

            $request->update(['google_reset_attempted_at' => now()]);

            $forceChange = $this->forceChangeAtNextLogin($request);

            try {
                $directoryService->resetPassword($request->student, $plainPassword, $forceChange);
            } catch (GoogleWorkspaceException $exception) {
                $request->update([
                    'google_reset_success' => false,
                    'google_error_message' => 'Google password reset failed.',
                ]);

                $pendingPasswords->deleteOnGoogleFailure($request);

                $auditLog->logSystem('google.reset.failed', 'password_reset_request', (string) $request->id, [
                    'student_id' => $request->student_id,
                ]);

                Log::error('Google password reset failed.', [
                    'request_id' => $request->id,
                    'student_id' => $request->student_id,
                ]);

                $this->notifySlackOutcome($slackApproval, $request, false);

                throw $exception;
            }

            $pendingPasswords->delete($request, 'approval');

            $request->update([
                'status' => PasswordResetRequestStatus::Completed,
                'google_reset_success' => true,
                'google_error_message' => null,
            ]);

            $auditLog->logSystem('google.reset.success', 'password_reset_request', (string) $request->id, [
                'student_id' => $request->student_id,
                'kiosk_id' => $request->kiosk_id,
            ]);

            $this->notifySlackOutcome($slackApproval, $request, true);
        });
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

    private function forceChangeAtNextLogin(PasswordResetRequest $request): bool
    {
        if ($request->office_verified_at !== null) {
            return true;
        }

        $type = $request->pending_password_type;

        if ($type === PendingPasswordType::StudentSelected->value) {
            return (bool) config('student-password-reset.google_force_change_at_next_login.student_selected');
        }

        return (bool) config('student-password-reset.google_force_change_at_next_login.temporary_generated');
    }

    private function notifySlackOutcome(
        SlackApprovalService $slackApproval,
        PasswordResetRequest $request,
        bool $success,
    ): void {
        if ($request->slack_channel_id === null || $request->slack_message_ts === null) {
            return;
        }

        try {
            $slackApproval->appendGoogleResetStatus($request, $success);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update Slack message after Google reset.', [
                'request_id' => $request->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}

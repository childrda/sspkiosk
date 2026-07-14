<?php

namespace App\Console\Commands;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PasswordResetRevisionStatus;
use App\Models\PasswordResetRequest;
use App\Services\AuditLogService;
use App\Services\PendingPasswordService;
use App\Services\PasswordRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireStaleResetRequestsCommand extends Command
{
    protected $signature = 'ssp:expire-requests';

    protected $description = 'Expire office-verification windows and expired pending passwords on active revisions';

    public function handle(
        PendingPasswordService $pendingPasswords,
        PasswordRevisionService $revisions,
        AuditLogService $auditLog,
    ): int {
        $expired = 0;

        PasswordResetRequest::query()
            ->where('status', PasswordResetRequestStatus::NeedsOfficeVerification)
            ->whereNotNull('office_verification_expires_at')
            ->where('office_verification_expires_at', '<', now())
            ->orderBy('id')
            ->each(function (PasswordResetRequest $request) use ($pendingPasswords, $auditLog, &$expired): void {
                $pendingPasswords->delete($request, 'expiration');

                $request->update(['status' => PasswordResetRequestStatus::Expired]);

                $auditLog->logSystem(
                    'request.office_verification.expired',
                    'password_reset_request',
                    (string) $request->id,
                    ['student_id' => $request->student_id],
                );

                $expired++;
            });

        $partialExpired = 0;

        PasswordResetRequest::query()
            ->whereIn('status', [
                PasswordResetRequestStatus::PartiallyCompleted,
                PasswordResetRequestStatus::ApprovedProcessing,
                PasswordResetRequestStatus::AwaitingPasswordReselection,
                PasswordResetRequestStatus::Pending,
            ])
            ->orderBy('id')
            ->each(function (PasswordResetRequest $request) use ($revisions, $pendingPasswords, $auditLog, &$partialExpired): void {
                DB::transaction(function () use ($request, $revisions, $pendingPasswords, $auditLog, &$partialExpired): void {
                    $locked = PasswordResetRequest::query()->lockForUpdate()->find($request->id);
                    if ($locked === null) {
                        return;
                    }

                    $revision = $revisions->activeRevision($locked);
                    if ($revision === null || ! $revision->pendingPasswordExpired()) {
                        return;
                    }

                    $pendingPasswords->deleteRevision($revision, 'expiration');
                    $revision->forceFill([
                        'retry_available' => false,
                        'status' => PasswordResetRevisionStatus::Failed,
                        'active_for_request_id' => null,
                        'superseded_at' => $revision->superseded_at ?? now(),
                    ])->save();

                    $locked->forceFill([
                        'status' => PasswordResetRequestStatus::Failed,
                        'retry_available' => false,
                    ])->save();

                    $revisions->projectToRequest($locked, $revision);

                    $auditLog->logSystem('password_revision.expired', 'password_reset_request', (string) $locked->id, [
                        'student_id' => $locked->student_id,
                        'revision_number' => $revision->revision_number,
                        'split_directory' => $locked->status === PasswordResetRequestStatus::Failed
                            && is_array($revision->directory_results),
                    ]);

                    $partialExpired++;
                });
            });

        $this->info("Expired {$expired} stale office-verification request(s).");
        $this->info("Expired {$partialExpired} revision(s) with elapsed pending passwords.");

        return self::SUCCESS;
    }
}

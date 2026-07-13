<?php

namespace App\Console\Commands;

use App\Enums\PasswordResetRequestStatus;
use App\Models\PasswordResetRequest;
use App\Services\AuditLogService;
use App\Services\PendingPasswordService;
use Illuminate\Console\Command;

class ExpireStaleResetRequestsCommand extends Command
{
    protected $signature = 'ssp:expire-requests';

    protected $description = 'Expire office-verification reset requests past their verification window';

    public function handle(PendingPasswordService $pendingPasswords, AuditLogService $auditLog): int
    {
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

        $this->info("Expired {$expired} stale office-verification request(s).");

        return self::SUCCESS;
    }
}

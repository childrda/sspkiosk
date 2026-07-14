<?php

namespace App\Services;

use App\Enums\KioskEnrollmentType;
use App\Enums\KioskStatus;
use App\Models\Kiosk;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AdminKioskService
{
    public function __construct(
        private readonly KioskEnrollmentService $enrollment,
        private readonly KioskCredentialService $credentials,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{name: string, school?: ?string, location?: ?string, allowed_ip?: ?string, allowed_subnet?: ?string}  $attributes
     * @return array{kiosk: Kiosk, enrollment_code: string}
     */
    public function createKiosk(array $attributes, int $adminUserId): array
    {
        return DB::transaction(function () use ($attributes, $adminUserId): array {
            $kiosk = $this->enrollment->createKiosk($attributes);
            $enrollmentCode = $this->enrollment->issueEnrollmentCode($kiosk);

            $this->auditLog->logAdmin(
                'admin.kiosk.created',
                $adminUserId,
                'kiosk',
                (string) $kiosk->id,
                ['name' => $kiosk->name],
            );

            return [
                'kiosk' => $kiosk,
                'enrollment_code' => $enrollmentCode,
            ];
        });
    }

    public function disable(Kiosk $kiosk, int $adminUserId): void
    {
        $kiosk->update(['status' => KioskStatus::Disabled]);

        $this->auditLog->logAdmin(
            'admin.kiosk.disabled',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );
    }

    public function enable(Kiosk $kiosk, int $adminUserId): void
    {
        $kiosk->update(['status' => KioskStatus::Active]);

        $this->auditLog->logAdmin(
            'admin.kiosk.enabled',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );
    }

    public function rotateSecret(Kiosk $kiosk, int $adminUserId): string
    {
        $secret = $this->credentials->generateSecret();
        $kiosk->update([
            'secret_hash' => $this->credentials->encryptSecret($secret),
        ]);

        $this->auditLog->logAdmin(
            'admin.kiosk.secret_rotated',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );

        return $secret;
    }

    public function issueEnrollmentCode(Kiosk $kiosk, int $adminUserId): string
    {
        if ($this->credentials->isEnrolled($kiosk)) {
            throw new \RuntimeException('Kiosk is already enrolled. Reset enrollment before issuing a new code.');
        }

        $code = $this->enrollment->issueEnrollmentCode($kiosk);

        $this->auditLog->logAdmin(
            'admin.kiosk.enrollment_code_issued',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );

        return $code;
    }

    public function resetReenrollment(Kiosk $kiosk, int $adminUserId): void
    {
        $kiosk->update([
            'enrolled_at' => null,
            'enrollment_type' => null,
            'secret_hash' => null,
        ]);

        $this->auditLog->logAdmin(
            'admin.kiosk.reenrollment_reset',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );
    }

    public function archive(Kiosk $kiosk, int $adminUserId): void
    {
        DB::transaction(function () use ($kiosk, $adminUserId): void {
            $kiosk->update([
                'status' => KioskStatus::Disabled,
                'secret_hash' => null,
                'enrolled_at' => null,
                'enrollment_type' => null,
            ]);

            $kiosk->enrollmentCodes()->delete();
            $kiosk->usedNonces()->delete();
            $kiosk->delete();

            $this->auditLog->logAdmin(
                'admin.kiosk.archived',
                $adminUserId,
                'kiosk',
                (string) $kiosk->id,
            );
        });
    }

    public function restore(Kiosk $kiosk, int $adminUserId): void
    {
        $kiosk->restore();

        if ($kiosk->status !== KioskStatus::Disabled) {
            $kiosk->update(['status' => KioskStatus::Disabled]);
        }

        $this->auditLog->logAdmin(
            'admin.kiosk.restored',
            $adminUserId,
            'kiosk',
            (string) $kiosk->id,
        );
    }

    public function isOnline(Kiosk $kiosk): bool
    {
        return $this->lastSeenIsFresh($kiosk);
    }

    public function lastSeenIsFresh(Kiosk $kiosk): bool
    {
        if ($kiosk->last_seen_at === null) {
            return false;
        }

        return $kiosk->last_seen_at->greaterThan(
            now()->subSeconds(config('kiosk.heartbeat_expires_after_seconds')),
        );
    }

    /**
     * Advisory last-seen label for admin UI.
     *
     * @return 'fresh'|'stale'|'asleep'|'never'
     */
    public function lastSeenStatus(Kiosk $kiosk, ?CarbonInterface $at = null): string
    {
        if ($kiosk->last_seen_at === null) {
            return 'never';
        }

        if ($this->lastSeenIsFresh($kiosk)) {
            return 'fresh';
        }

        if (! $this->isWithinStalenessWindow($at)) {
            return 'asleep';
        }

        return 'stale';
    }

    public function isWithinStalenessWindow(?CarbonInterface $at = null): bool
    {
        $timezone = (string) config('kiosk.staleness_window.timezone', 'America/New_York');
        $moment = Carbon::parse($at ?? now())->timezone($timezone);
        $start = Carbon::parse($moment->toDateString().' '.config('kiosk.staleness_window.start'), $timezone);
        $end = Carbon::parse($moment->toDateString().' '.config('kiosk.staleness_window.end'), $timezone);

        return $moment->betweenIncluded($start, $end);
    }
}

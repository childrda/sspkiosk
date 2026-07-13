<?php

namespace App\Console\Commands;

use App\Models\Kiosk;
use App\Services\KioskEnrollmentService;
use Illuminate\Console\Command;

class CreateKioskEnrollmentCodeCommand extends Command
{
    protected $signature = 'kiosk:enrollment-code {kiosk : Kiosk ID or UUID}';

    protected $description = 'Generate a one-time enrollment code for a kiosk';

    public function handle(KioskEnrollmentService $enrollment): int
    {
        $kiosk = $this->resolveKiosk($this->argument('kiosk'));

        if (! $kiosk) {
            $this->error('Kiosk not found.');

            return self::FAILURE;
        }

        if ($kiosk->enrolled_at !== null) {
            $this->warn('This kiosk is already enrolled. Reset enrollment from the admin dashboard before re-enrolling.');
        }

        $code = $enrollment->issueEnrollmentCode($kiosk);

        $this->info('One-time enrollment code (give this to the kiosk installer):');
        $this->line($code);
        $this->line('Expires in '.config('kiosk.enrollment_code_expires_minutes').' minutes.');

        return self::SUCCESS;
    }

    private function resolveKiosk(string $identifier): ?Kiosk
    {
        return Kiosk::query()
            ->where('id', $identifier)
            ->orWhere('kiosk_uuid', $identifier)
            ->first();
    }
}

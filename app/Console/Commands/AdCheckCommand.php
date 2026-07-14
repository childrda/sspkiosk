<?php

namespace App\Console\Commands;

use App\Services\ActiveDirectoryService;
use App\Services\AuditLogService;
use Illuminate\Console\Command;

class AdCheckCommand extends Command
{
    protected $signature = 'ssp:ad-check {--sam= : Optional sAMAccountName to resolve in the student OU}';

    protected $description = 'Validate Active Directory LDAPS configuration and connectivity';

    public function handle(ActiveDirectoryService $activeDirectory, AuditLogService $auditLog): int
    {
        $sample = $this->option('sam');
        $result = $activeDirectory->healthCheck(is_string($sample) && $sample !== '' ? $sample : null);

        $this->line('AD enabled: '.($result['enabled'] ? 'yes' : 'no'));
        $this->line('AD configured: '.($result['configured'] ? 'yes' : 'no'));
        $this->line('AD port: '.$result['port']);

        if ($result['bind_ok'] !== null) {
            $this->line('LDAPS bind: '.($result['bind_ok'] ? 'ok' : 'failed'));
        }

        if ($result['ou_readable'] !== null) {
            $this->line('Student OU readable: '.($result['ou_readable'] ? 'yes' : 'no'));
        }

        if ($result['sample_status'] !== null) {
            $this->line('Sample sAMAccountName status: '.$result['sample_status']);
        }

        $this->info($result['message']);

        $auditLog->logSystem('admin.ad_check.executed', 'system', 'ad-check', [
            'sam_supplied' => is_string($sample) && $sample !== '',
            'enabled' => $result['enabled'],
            'configured' => $result['configured'],
            'bind_ok' => $result['bind_ok'],
            'sample_status' => $result['sample_status'],
        ]);

        if (! $result['enabled']) {
            return self::SUCCESS;
        }

        return ($result['configured'] && $result['bind_ok'] === true) ? self::SUCCESS : self::FAILURE;
    }
}

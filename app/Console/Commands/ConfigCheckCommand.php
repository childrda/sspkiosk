<?php

namespace App\Console\Commands;

use App\Services\ConfigurationValidatorService;
use Illuminate\Console\Command;

class ConfigCheckCommand extends Command
{
    protected $signature = 'ssp:config-check
                            {--workflow= : Check a single workflow (google_auth, slack, kiosk_reset, google_password_reset)}';

    protected $description = 'Report missing required environment configuration for SSP Kiosk workflows';

    public function handle(ConfigurationValidatorService $validator): int
    {
        $workflow = $this->option('workflow');

        if ($workflow !== null) {
            $missing = match ($workflow) {
                'google_auth' => $validator->missingRequiredForGoogleAuth(),
                'google_directory_lookup' => $validator->missingRequiredForGoogleDirectoryLookup(),
                'google_password_reset' => $validator->missingRequiredForGooglePasswordReset(),
                'slack' => $validator->missingRequiredForSlack(),
                'kiosk_reset' => $validator->missingRequiredForKioskReset(),
                'active_directory' => $validator->missingRequiredForActiveDirectory(),
                default => null,
            };

            if ($missing === null) {
                $this->error('Unknown workflow. Use: google_auth, google_directory_lookup, google_password_reset, slack, kiosk_reset, active_directory');

                return self::FAILURE;
            }

            return $this->renderMissing($missing, $validator->warnings());
        }

        return $this->renderMissing($validator->allMissing(), $validator->warnings());
    }

    /**
     * @param  array<string, list<string>>  $missing
     * @param  list<string>  $warnings
     */
    private function renderMissing(array $missing, array $warnings = []): int
    {
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($missing === []) {
            $this->info('All checked configuration values are present.');

            return self::SUCCESS;
        }

        $this->warn('Missing required configuration:');

        foreach ($missing as $group => $keys) {
            foreach ($keys as $key) {
                $this->line("  [{$group}] {$key}");
            }
        }

        return self::FAILURE;
    }
}

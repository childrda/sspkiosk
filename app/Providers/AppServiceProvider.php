<?php

namespace App\Providers;

use App\Services\ActiveDirectoryService;
use App\Services\AuditLogService;
use App\Services\ConfigurationValidatorService;
use App\Services\DirectoryPasswordResetCoordinator;
use App\Services\GoogleWorkspaceDirectoryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(ConfigurationValidatorService::class);

        $this->app->when(DirectoryPasswordResetCoordinator::class)
            ->needs('$directoryResetters')
            ->give(function ($app) {
                return [
                    $app->make(GoogleWorkspaceDirectoryService::class),
                    $app->make(ActiveDirectoryService::class),
                ];
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $missing = $this->app->make(ConfigurationValidatorService::class)->allMissing();

        if ($missing !== []) {
            Log::warning('SSP Kiosk: required configuration is incomplete.', [
                'missing' => $missing,
            ]);
        }
    }
}

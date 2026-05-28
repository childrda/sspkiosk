<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            $config = config('security.rate_limits.admin_login');

            return Limit::perMinutes(
                $config['decay_minutes'],
                $config['max_attempts'],
            )->by($request->ip());
        });

        RateLimiter::for('kiosk-reset-lookup', function (Request $request): Limit {
            $config = config('security.rate_limits.kiosk_reset_lookup');

            return Limit::perMinutes(
                $config['decay_minutes'],
                $config['max_attempts'],
            )->by($request->ip().'|'.$request->session()->getId());
        });

        RateLimiter::for('kiosk-enroll', function (Request $request): Limit {
            $config = config('security.rate_limits.kiosk_enroll');

            return Limit::perMinutes(
                $config['decay_minutes'],
                $config['max_attempts'],
            )->by($request->ip());
        });

        RateLimiter::for('slack-interactions', function (Request $request): Limit {
            $config = config('security.rate_limits.slack_interactions');

            return Limit::perMinutes(
                $config['decay_minutes'],
                $config['max_attempts'],
            )->by($request->ip());
        });

        RateLimiter::for('slack-photo', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}

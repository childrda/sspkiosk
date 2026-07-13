<?php

namespace Tests\Unit;

use App\Services\ConfigurationValidatorService;
use Tests\TestCase;

class ConfigurationValidatorServiceTest extends TestCase
{
    public function test_reports_missing_google_auth_configuration(): void
    {
        config([
            'google-workspace.student_domain' => '',
            'google-workspace.oauth.client_id' => '',
            'google-workspace.oauth.client_secret' => '',
            'google-workspace.oauth.redirect_uri' => '',
        ]);

        $missing = (new ConfigurationValidatorService)->missingRequiredForGoogleAuth();

        $this->assertArrayHasKey('google-workspace', $missing);
        $this->assertContains('STUDENT_GOOGLE_DOMAIN', $missing['google-workspace']);
    }

    public function test_kiosk_reset_requires_allowed_networks_when_enabled(): void
    {
        config([
            'student-password-reset.reset_requires_kiosk' => true,
            'kiosk.allowed_networks' => [],
        ]);

        $missing = (new ConfigurationValidatorService)->missingRequiredForKioskReset();

        $this->assertArrayHasKey('kiosk', $missing);
        $this->assertContains('KIOSK_ALLOWED_NETWORKS', $missing['kiosk']);
    }

    public function test_kiosk_reset_requires_valid_reset_password_mode(): void
    {
        config(['student-password-reset.reset_password_mode' => 'not_a_real_mode']);

        $missing = (new ConfigurationValidatorService)->missingRequiredForKioskReset();

        $this->assertArrayHasKey('student-password-reset', $missing);
        $this->assertContains('RESET_PASSWORD_MODE', $missing['student-password-reset']);
    }

    public function test_kiosk_reset_rejects_localhost_app_url_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'http://localhost']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertArrayHasKey('app', $missing);
        $this->assertContains('APP_URL (must use https)', $missing['app']);
    }

    public function test_kiosk_reset_rejects_http_app_url_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'http://kiosk.example.org']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertArrayHasKey('app', $missing);
        $this->assertContains('APP_URL (must use https)', $missing['app']);
    }

    public function test_kiosk_reset_accepts_https_production_app_url(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://kiosk.example.org']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertSame([], $missing);
    }

    public function test_kiosk_reset_allows_localhost_app_url_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://localhost']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertSame([], $missing);
    }

    public function test_merge_missing_appends_colliding_group_keys(): void
    {
        $validator = new ConfigurationValidatorService;
        $method = new \ReflectionMethod($validator, 'mergeMissing');
        $method->setAccessible(true);

        $merged = $method->invoke($validator, [
            'kiosk' => ['KIOSK_ALLOWED_NETWORKS'],
            'app' => ['APP_URL (must use https)'],
        ], [
            'kiosk' => ['KIOSK_HEARTBEAT_EXPIRES_AFTER_SECONDS (must be at least 3× KIOSK_HEARTBEAT_INTERVAL_SECONDS)'],
        ]);

        $this->assertCount(2, $merged['kiosk']);
        $this->assertContains('KIOSK_ALLOWED_NETWORKS', $merged['kiosk']);
        $this->assertContains('APP_URL (must use https)', $merged['app']);
    }
}

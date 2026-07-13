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

    public function test_kiosk_reset_rejects_localhost_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertArrayHasKey('app', $missing);
        $this->assertContains('APP_URL (must use https)', $missing['app']);
    }

    public function test_kiosk_reset_rejects_http_app_url(): void
    {
        config(['app.url' => 'http://kiosk.example.org']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertArrayHasKey('app', $missing);
        $this->assertContains('APP_URL (must use https)', $missing['app']);
    }

    public function test_kiosk_reset_accepts_https_production_app_url(): void
    {
        config(['app.url' => 'https://kiosk.example.org']);

        $missing = (new ConfigurationValidatorService)->missingInvalidAppUrl();

        $this->assertSame([], $missing);
    }
}

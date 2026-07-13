<?php

namespace App\Services;

use App\Enums\ResetPasswordMode;

class ConfigurationValidatorService
{
    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredForGoogleAuth(): array
    {
        return $this->missingKeys([
            'google-workspace.student_domain' => 'STUDENT_GOOGLE_DOMAIN',
            'google-workspace.oauth.client_id' => 'GOOGLE_CLIENT_ID',
            'google-workspace.oauth.client_secret' => 'GOOGLE_CLIENT_SECRET',
            'google-workspace.oauth.redirect_uri' => 'GOOGLE_REDIRECT_URI',
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredForGoogleDirectoryLookup(): array
    {
        if (! $this->requiresGoogleDirectoryLookup()) {
            return [];
        }

        return $this->missingKeys([
            'google-workspace.service_account_json_path' => 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH',
            'google-workspace.admin_impersonation_email' => 'GOOGLE_ADMIN_IMPERSONATION_EMAIL',
        ]);
    }

    public function requiresGoogleDirectoryLookup(): bool
    {
        return config('google-workspace.allowed_student_org_units') !== []
            || config('google-workspace.blocked_staff_org_units') !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredForGooglePasswordReset(): array
    {
        return $this->missingKeys([
            'google-workspace.service_account_json_path' => 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH',
            'google-workspace.admin_impersonation_email' => 'GOOGLE_ADMIN_IMPERSONATION_EMAIL',
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredForSlack(): array
    {
        return $this->missingKeys([
            'slack.bot_token' => 'SLACK_BOT_TOKEN',
            'slack.signing_secret' => 'SLACK_SIGNING_SECRET',
            'slack.reset_channel_id' => 'SLACK_RESET_CHANNEL_ID',
            'slack.approver_usergroup_id' => 'SLACK_APPROVER_USERGROUP_ID',
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingRequiredForKioskReset(): array
    {
        $missing = [];

        if (config('student-password-reset.reset_requires_kiosk') && empty(config('kiosk.allowed_networks'))) {
            $missing['kiosk'][] = 'KIOSK_ALLOWED_NETWORKS';
        }

        if (ResetPasswordMode::tryFromConfig() === null) {
            $missing['student-password-reset'][] = 'RESET_PASSWORD_MODE';
        }

        if (config('kiosk.require_active_heartbeat')) {
            $interval = (int) config('kiosk.heartbeat_interval_seconds');
            $expires = (int) config('kiosk.heartbeat_expires_after_seconds');

            if ($expires < $interval * 3) {
                $missing['kiosk'][] = 'KIOSK_HEARTBEAT_EXPIRES_AFTER_SECONDS (must be at least 3× KIOSK_HEARTBEAT_INTERVAL_SECONDS)';
            }
        }

        return $this->mergeMissing($missing, $this->missingInvalidAppUrl());
    }

    /**
     * @return array<string, list<string>>
     */
    public function missingInvalidAppUrl(): array
    {
        $url = trim((string) config('app.url'));

        if ($url === '') {
            return ['app' => ['APP_URL']];
        }

        if (! app()->environment('production')) {
            return [];
        }

        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return ['app' => ['APP_URL (must be a valid URL)']];
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            return ['app' => ['APP_URL (must use https)']];
        }

        $host = strtolower($parsed['host']);

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
            return ['app' => ['APP_URL (must not be localhost)']];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function allMissing(): array
    {
        return $this->mergeMissing(
            $this->missingRequiredForGoogleAuth(),
            $this->missingRequiredForGooglePasswordReset(),
            $this->missingRequiredForSlack(),
            $this->missingRequiredForKioskReset(),
        );
    }

    public function isWorkflowConfigured(string $workflow): bool
    {
        return match ($workflow) {
            'google_auth' => empty($this->missingRequiredForGoogleAuth()),
            'google_directory_lookup' => empty($this->missingRequiredForGoogleDirectoryLookup()),
            'google_password_reset' => empty($this->missingRequiredForGooglePasswordReset()),
            'slack' => empty($this->missingRequiredForSlack()),
            'kiosk_reset' => empty($this->missingRequiredForKioskReset()),
            default => false,
        };
    }

    /**
     * @param  array<string, list<string>>  ...$groups
     * @return array<string, list<string>>
     */
    private function mergeMissing(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $merged = array_merge_recursive($merged, $group);
        }

        return $merged;
    }

    /**
     * @param  array<string, string>  $configToEnv
     * @return array<string, list<string>>
     */
    private function missingKeys(array $configToEnv): array
    {
        $missing = [];

        foreach ($configToEnv as $configKey => $envKey) {
            $value = config($configKey);

            if ($value === null || $value === '' || $value === []) {
                $group = explode('.', $configKey)[0];
                $missing[$group][] = $envKey;
            }
        }

        return $missing;
    }
}

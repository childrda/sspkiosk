<?php

namespace Tests\Feature;

use App\Enums\KioskEnrollmentType;
use App\Enums\KioskStatus;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Services\KioskCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskIpSessionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function configureKioskReset(): void
    {
        config([
            'kiosk.allowed_networks' => [],
            'student-password-reset.reset_password_mode' => 'temporary_generated',
        ]);
    }

    private function enrolledKiosk(array $attributes = []): Kiosk
    {
        return Kiosk::factory()->enrolled()->create(array_merge([
            'status' => KioskStatus::Active,
        ], $attributes));
    }

    private function getResetIndexFromIp(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('kiosk.reset.index'));
    }

    public function test_empty_session_with_exact_allowed_ip_match_seeds_session(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->enrolledKiosk(['allowed_ip' => '10.10.20.15']);

        $response = $this->getResetIndexFromIp('10.10.20.15');

        $response->assertOk();
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
        $this->assertTrue(
            AuditLog::query()->where('action', 'kiosk.session.ip_resolved')->exists(),
        );
    }

    public function test_empty_session_with_no_matching_kiosk_redirects_to_unavailable(): void
    {
        $this->configureKioskReset();

        $this->enrolledKiosk(['allowed_ip' => '10.10.20.15']);

        $response = $this->getResetIndexFromIp('10.10.20.99');

        $response->assertRedirect(route('kiosk.reset.unavailable'));
        $response->assertSessionMissing(config('kiosk.registration_session_kiosk_key'));
    }

    public function test_empty_session_with_ambiguous_exact_ip_redirects_to_unavailable(): void
    {
        $this->configureKioskReset();

        $this->enrolledKiosk(['allowed_ip' => '10.10.20.15', 'name' => 'Kiosk A']);
        $this->enrolledKiosk(['allowed_ip' => '10.10.20.15', 'name' => 'Kiosk B']);

        $response = $this->getResetIndexFromIp('10.10.20.15');

        $response->assertRedirect(route('kiosk.reset.unavailable'));
        $response->assertSessionMissing(config('kiosk.registration_session_kiosk_key'));
    }

    public function test_disabled_kiosk_is_not_resolved_from_ip(): void
    {
        $this->configureKioskReset();

        $this->enrolledKiosk([
            'allowed_ip' => '10.10.20.15',
            'status' => KioskStatus::Disabled,
        ]);

        $response = $this->getResetIndexFromIp('10.10.20.15');

        $response->assertRedirect(route('kiosk.reset.unavailable'));
        $response->assertSessionMissing(config('kiosk.registration_session_kiosk_key'));
    }

    public function test_kiosk_without_enrolled_at_is_not_resolved_from_ip(): void
    {
        $this->configureKioskReset();

        Kiosk::factory()->create([
            'allowed_ip' => '10.10.20.15',
            'enrolled_at' => null,
            'status' => KioskStatus::Active,
        ]);

        $response = $this->getResetIndexFromIp('10.10.20.15');

        $response->assertRedirect(route('kiosk.reset.unavailable'));
        $response->assertSessionMissing(config('kiosk.registration_session_kiosk_key'));
    }

    public function test_session_bound_to_kiosk_a_rebinds_when_request_arrives_from_kiosk_b_ip(): void
    {
        $this->configureKioskReset();

        $kioskA = $this->enrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.20.0/24',
            'name' => 'Kiosk A',
        ]);
        $kioskB = $this->enrolledKiosk([
            'allowed_ip' => '10.10.20.20',
            'allowed_subnet' => null,
            'name' => 'Kiosk B',
        ]);

        $sessionKey = config('kiosk.registration_session_kiosk_key');

        $response = $this->withSession([$sessionKey => $kioskA->id])
            ->withServerVariables(['REMOTE_ADDR' => '10.10.20.20'])
            ->get(route('kiosk.reset.index'));

        $response->assertOk();
        $response->assertSessionHas($sessionKey, $kioskB->id);
        $this->assertTrue(
            AuditLog::query()->where('action', 'kiosk.session.ip_mismatch')->exists(),
        );
    }

    public function test_subnet_fallback_seeds_session_when_no_exact_match(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->enrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.30.0/24',
        ]);

        $response = $this->getResetIndexFromIp('10.10.30.44');

        $response->assertOk();
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
    }

    public function test_exact_allowed_ip_wins_over_subnet_match(): void
    {
        $this->configureKioskReset();

        $kioskA = $this->enrolledKiosk([
            'allowed_ip' => '10.10.40.50',
            'allowed_subnet' => null,
            'name' => 'Exact match kiosk',
        ]);
        $this->enrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.40.0/24',
            'name' => 'Subnet kiosk',
        ]);

        $response = $this->getResetIndexFromIp('10.10.40.50');

        $response->assertOk();
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kioskA->id);
    }

    public function test_ambiguous_subnet_match_redirects_to_unavailable(): void
    {
        $this->configureKioskReset();

        $this->enrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.50.0/24',
        ]);
        $this->enrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.50.0/24',
        ]);

        $response = $this->getResetIndexFromIp('10.10.50.77');

        $response->assertRedirect(route('kiosk.reset.unavailable'));
        $response->assertSessionMissing(config('kiosk.registration_session_kiosk_key'));
    }
}

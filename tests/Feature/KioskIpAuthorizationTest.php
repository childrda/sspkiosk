<?php

namespace Tests\Feature;

use App\Enums\KioskEnrollmentType;
use App\Enums\KioskStatus;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Services\KioskCredentialService;
use App\Services\KioskNetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class KioskIpAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    private function configureKioskReset(): void
    {
        config([
            'kiosk.allowed_networks' => ['10.10.0.0/16'],
            'student-password-reset.reset_password_mode' => 'temporary_generated',
        ]);
    }

    private function browserEnrolledKiosk(array $attributes = []): Kiosk
    {
        return Kiosk::factory()->browserEnrolled()->create(array_merge([
            'status' => KioskStatus::Active,
        ], $attributes));
    }

    public function test_browser_enrolled_kiosk_without_secret_resolves_by_allowed_ip(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk([
            'allowed_ip' => '10.10.20.15',
            'secret_hash' => null,
        ]);

        $resolved = app(KioskNetworkService::class)->findEnrolledKioskByIp('10.10.20.15');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($kiosk));
    }

    public function test_request_inside_allowed_networks_but_not_matching_kiosk_allowed_ip_is_rejected(): void
    {
        config(['kiosk.allowed_networks' => ['10.10.0.0/16']]);

        $kiosk = $this->browserEnrolledKiosk(['allowed_ip' => '10.10.20.15']);

        $allowed = app(KioskNetworkService::class)->isRequestIpAllowed(
            request()->create('/kiosk/reset', 'GET', [], [], [], ['REMOTE_ADDR' => '10.10.20.99']),
            $kiosk,
        );

        $this->assertFalse($allowed);
    }

    public function test_session_bound_to_kiosk_a_from_kiosk_b_ip_rebinds_to_b(): void
    {
        $this->configureKioskReset();

        $kioskA = $this->browserEnrolledKiosk([
            'allowed_ip' => null,
            'allowed_subnet' => '10.10.30.0/24',
            'name' => 'Kiosk A',
        ]);
        $kioskB = $this->browserEnrolledKiosk([
            'allowed_ip' => '10.10.20.20',
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

    public function test_session_bound_to_kiosk_with_unresolvable_ip_redirects_to_unavailable(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk(['allowed_ip' => '10.10.20.15']);
        $sessionKey = config('kiosk.registration_session_kiosk_key');

        $response = $this->withSession([$sessionKey => $kiosk->id])
            ->withServerVariables(['REMOTE_ADDR' => '10.10.20.99'])
            ->get(route('kiosk.reset.index'));

        $response->assertRedirect(route('kiosk.reset.unavailable'));
    }

    public function test_two_kiosks_sharing_allowed_ip_fail_closed(): void
    {
        $this->configureKioskReset();

        $this->browserEnrolledKiosk(['allowed_ip' => '10.10.20.15', 'name' => 'Kiosk A']);
        $this->browserEnrolledKiosk(['allowed_ip' => '10.10.20.15', 'name' => 'Kiosk B']);

        $this->assertNull(app(KioskNetworkService::class)->findEnrolledKioskByIp('10.10.20.15'));
    }

    public function test_reset_is_reachable_with_stale_last_seen_at(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk([
            'allowed_ip' => '10.10.20.15',
            'last_seen_at' => now()->subHours(6),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.15'])
            ->get(route('kiosk.reset.index'))
            ->assertOk()
            ->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
    }

    public function test_session_heartbeat_updates_last_seen_without_audit_row(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk([
            'allowed_ip' => '10.10.20.15',
            'last_seen_at' => now()->subHour(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.15'])
            ->post(route('kiosk.session-heartbeat'))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertTrue($kiosk->fresh()->last_seen_at->greaterThan(now()->subMinute()));
        $this->assertFalse(
            AuditLog::query()->where('action', 'kiosk.heartbeat')->exists(),
        );
    }

    public function test_archived_kiosk_is_not_ip_resolvable(): void
    {
        $this->configureKioskReset();

        $admin = \App\Models\User::factory()->admin()->create();
        $kiosk = $this->browserEnrolledKiosk(['allowed_ip' => '10.10.20.15']);

        app(\App\Services\AdminKioskService::class)->archive($kiosk, $admin->id);

        $this->assertNull(app(KioskNetworkService::class)->findEnrolledKioskByIp('10.10.20.15'));
    }

    public function test_hmac_heartbeat_still_works_for_device_agent_kiosk(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();

        $kiosk = Kiosk::factory()->create([
            'secret_hash' => $credentials->encryptSecret($secret),
            'enrolled_at' => now(),
            'enrollment_type' => KioskEnrollmentType::DeviceAgent,
            'allowed_ip' => '127.0.0.1',
            'last_seen_at' => null,
        ]);

        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);

        $this->postSignedKioskRequest($headers, $body)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertNotNull($kiosk->fresh()->last_seen_at);
    }
}

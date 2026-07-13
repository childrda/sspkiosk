<?php

namespace Tests\Feature;

use App\Models\Kiosk;
use App\Models\UsedNonce;
use App\Models\User;
use App\Services\KioskCredentialService;
use App\Services\KioskEnrollmentService;
use App\Services\KioskSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class KioskHeartbeatAgentTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    private function enrolledKiosk(array $attributes = []): array
    {
        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();

        $kiosk = Kiosk::factory()->create(array_merge([
            'secret_hash' => $credentials->encryptSecret($secret),
            'last_seen_at' => null,
        ], $attributes));

        return [$kiosk, $secret];
    }

    public function test_valid_signed_heartbeat_updates_last_seen_at(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);

        $response = $this->postSignedKioskRequest($headers, $body);

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'heartbeat_interval_seconds' => config('kiosk.heartbeat_interval_seconds'),
        ]);

        $kiosk->refresh();
        $this->assertNotNull($kiosk->last_seen_at);
    }

    public function test_heartbeat_rejects_signature_computed_over_different_body(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $body = json_encode(['device_fingerprint' => 'fp-a']);
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);
        $wrongBody = json_encode(['device_fingerprint' => 'fp-b']);

        $this->postSignedKioskRequest($headers, $wrongBody)
            ->assertUnauthorized()
            ->assertJson(['reason' => 'invalid_signature']);
    }

    public function test_heartbeat_rejects_replayed_nonce(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $nonce = (string) Str::uuid();
        $timestamp = (string) now()->timestamp;

        $headers = [
            KioskSecurityService::HEADER_KIOSK_ID => $kiosk->kiosk_uuid,
            KioskSecurityService::HEADER_TIMESTAMP => $timestamp,
            KioskSecurityService::HEADER_NONCE => $nonce,
            KioskSecurityService::HEADER_SIGNATURE => app(KioskSecurityService::class)->signPayload(
                implode("\n", [
                    $kiosk->kiosk_uuid,
                    $timestamp,
                    $nonce,
                    'POST',
                    '/kiosk/heartbeat',
                    hash('sha256', $body),
                ]),
                $secret,
            ),
            'Content-Type' => 'application/json',
        ];

        $this->postSignedKioskRequest($headers, $body)->assertOk();
        $this->postSignedKioskRequest($headers, $body)
            ->assertUnauthorized()
            ->assertJson(['reason' => 'nonce_reused']);
    }

    public function test_heartbeat_rejects_expired_timestamp(): void
    {
        config([
            'kiosk.allowed_networks' => ['127.0.0.1'],
            'kiosk.hmac_tolerance_seconds' => 60,
        ]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $nonce = (string) Str::uuid();
        $timestamp = (string) now()->subSeconds(120)->timestamp;

        $headers = [
            KioskSecurityService::HEADER_KIOSK_ID => $kiosk->kiosk_uuid,
            KioskSecurityService::HEADER_TIMESTAMP => $timestamp,
            KioskSecurityService::HEADER_NONCE => $nonce,
            KioskSecurityService::HEADER_SIGNATURE => app(KioskSecurityService::class)->signPayload(
                implode("\n", [
                    $kiosk->kiosk_uuid,
                    $timestamp,
                    $nonce,
                    'POST',
                    '/kiosk/heartbeat',
                    hash('sha256', $body),
                ]),
                $secret,
            ),
            'Content-Type' => 'application/json',
        ];

        $this->postSignedKioskRequest($headers, $body)
            ->assertUnauthorized()
            ->assertJson(['reason' => 'timestamp_expired']);
    }

    public function test_fresh_heartbeat_allows_reset_but_stale_redirects_to_unavailable(): void
    {
        config([
            'kiosk.allowed_networks' => ['127.0.0.1'],
            'kiosk.require_active_heartbeat' => true,
            'kiosk.heartbeat_expires_after_seconds' => 300,
            'student-password-reset.reset_password_mode' => 'temporary_generated',
        ]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);

        $this->postSignedKioskRequest($headers, $body)->assertOk();

        $this->withSession([config('kiosk.registration_session_kiosk_key') => $kiosk->id])
            ->get(route('kiosk.reset.index'))
            ->assertOk();

        $expiresAfter = (int) config('kiosk.heartbeat_expires_after_seconds');
        Carbon::setTestNow(now()->addSeconds($expiresAfter + 1));

        $this->withSession([config('kiosk.registration_session_kiosk_key') => $kiosk->id])
            ->get(route('kiosk.reset.index'))
            ->assertRedirect(route('kiosk.reset.unavailable'));

        Carbon::setTestNow();
    }

    public function test_prune_nonces_deletes_stale_rows_and_keeps_recent_rows(): void
    {
        config(['kiosk.hmac_tolerance_seconds' => 300]);

        [$kiosk] = $this->enrolledKiosk();

        UsedNonce::query()->create([
            'kiosk_id' => $kiosk->id,
            'nonce' => (string) Str::uuid(),
            'created_at' => now()->subSeconds(700),
        ]);

        $recentNonce = (string) Str::uuid();
        UsedNonce::query()->create([
            'kiosk_id' => $kiosk->id,
            'nonce' => $recentNonce,
            'created_at' => now()->subSeconds(100),
        ]);

        $this->artisan('ssp:prune-nonces')->assertSuccessful();

        $this->assertSame(1, UsedNonce::query()->where('kiosk_id', $kiosk->id)->count());
        $this->assertTrue(UsedNonce::query()->where('nonce', $recentNonce)->exists());
    }

    public function test_browser_enrollment_flashes_secret_and_shows_complete_page(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $enrollment = app(KioskEnrollmentService::class);
        $kiosk = $enrollment->createKiosk(['name' => 'Browser Kiosk']);
        $code = $enrollment->issueEnrollmentCode($kiosk);

        $response = $this->post(route('kiosk.enroll'), [
            'enrollment_code' => $code,
        ]);

        $response->assertRedirect(route('kiosk.enroll.complete'));
        $response->assertSessionHas('kiosk_secret');
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);

        $this->get(route('kiosk.enroll.complete'))
            ->assertOk()
            ->assertSee('Store this device secret now', false);
    }

    public function test_admin_provisioning_bundle_downloads_agent_conf_once(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $admin = User::factory()->admin()->create();
        [$kiosk, $secret] = $this->enrolledKiosk();

        $response = $this->actingAs($admin)
            ->withSession([
                'kiosk_secret' => $secret,
                'kiosk_secret_for' => $kiosk->id,
            ])
            ->get(route('admin.kiosks.provisioning-bundle', $kiosk));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $response->assertSee('SSPKIOSK_KIOSK_UUID='.$kiosk->kiosk_uuid, false);
        $response->assertSee('SSPKIOSK_SECRET='.$secret, false);

        $this->actingAs($admin)
            ->get(route('admin.kiosks.provisioning-bundle', $kiosk))
            ->assertNotFound();
    }
}

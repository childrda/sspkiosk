<?php

namespace Tests\Feature;

use App\Models\Kiosk;
use App\Models\UsedNonce;
use App\Services\KioskCredentialService;
use App\Services\KioskSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class KioskSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    private function enrolledKiosk(): array
    {
        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();

        $kiosk = Kiosk::factory()->create([
            'secret_hash' => $credentials->encryptSecret($secret),
            'enrolled_at' => now(),
            'enrollment_type' => \App\Enums\KioskEnrollmentType::DeviceAgent,
            'allowed_ip' => '127.0.0.1',
            'last_seen_at' => now(),
        ]);

        return [$kiosk, $secret];
    }

    public function test_heartbeat_requires_valid_signature(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();

        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat');

        $this->withHeaders($headers)->post(route('kiosk.heartbeat'))->assertOk();
    }

    public function test_heartbeat_rejects_invalid_signature(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();

        $headers = $this->kioskAuthHeaders($kiosk, $secret.'invalid', 'POST', '/kiosk/heartbeat');

        $this->withHeaders($headers)->post(route('kiosk.heartbeat'))->assertUnauthorized();
    }

    public function test_heartbeat_rejects_reused_nonce(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
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
                    hash('sha256', ''),
                ]),
                $secret,
            ),
        ];

        $this->withHeaders($headers)->post(route('kiosk.heartbeat'))->assertOk();
        $this->withHeaders($headers)->post(route('kiosk.heartbeat'))->assertUnauthorized();
        $this->assertSame(1, UsedNonce::query()->where('kiosk_id', $kiosk->id)->count());
    }

    public function test_heartbeat_rejects_disallowed_ip(): void
    {
        config(['kiosk.allowed_networks' => ['10.0.0.0/8']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat');

        $this->withHeaders($headers)->post(route('kiosk.heartbeat'))->assertUnauthorized();
    }

    public function test_bind_session_sets_registration_kiosk_session(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        [$kiosk, $secret] = $this->enrolledKiosk();
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/bind-session');

        $response = $this->withHeaders($headers)->post(route('kiosk.bind-session'));

        $response->assertOk();
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\KioskEnrollmentType;
use App\Models\Kiosk;
use App\Services\KioskCredentialService;
use App\Services\KioskEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_can_enroll_with_valid_code(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $enrollment = app(KioskEnrollmentService::class);
        $kiosk = $enrollment->createKiosk(['name' => 'Library Kiosk', 'allowed_ip' => '127.0.0.1']);
        $code = $enrollment->issueEnrollmentCode($kiosk);

        $response = $this->postJson(route('kiosk.enroll'), [
            'enrollment_code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['kiosk_uuid', 'secret', 'kiosk_id']);

        $kiosk->refresh();
        $this->assertNotNull($kiosk->secret_hash);
        $this->assertNotNull($kiosk->enrolled_at);
        $this->assertSame(KioskEnrollmentType::DeviceAgent, $kiosk->enrollment_type);
        $this->assertTrue(app(KioskCredentialService::class)->hasDeviceAgentCredential($kiosk));
    }

    public function test_enrollment_code_cannot_be_reused(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $enrollment = app(KioskEnrollmentService::class);
        $kiosk = $enrollment->createKiosk(['name' => 'Lab Kiosk', 'allowed_ip' => '127.0.0.1']);
        $code = $enrollment->issueEnrollmentCode($kiosk);

        $this->postJson(route('kiosk.enroll'), ['enrollment_code' => $code])->assertOk();
        $this->postJson(route('kiosk.enroll'), ['enrollment_code' => $code])->assertUnauthorized();
    }

    public function test_browser_enrollment_sets_session_and_redirects_to_complete_page(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $enrollment = app(KioskEnrollmentService::class);
        $kiosk = $enrollment->createKiosk([
            'name' => 'Browser Kiosk',
            'allowed_ip' => '10.10.20.15',
        ]);
        $code = $enrollment->issueEnrollmentCode($kiosk);

        $this->get(route('kiosk.enroll.form'))->assertOk();

        $response = $this->post(route('kiosk.enroll'), [
            'enrollment_code' => $code,
        ]);

        $response->assertRedirect(route('kiosk.enroll.complete'));
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
        $response->assertSessionHas('browser_enrollment', true);
        $response->assertSessionMissing('kiosk_secret');

        $kiosk->refresh();
        $this->assertNull($kiosk->secret_hash);
        $this->assertSame(KioskEnrollmentType::Browser, $kiosk->enrollment_type);
        $this->assertNotNull($kiosk->enrolled_at);
    }
}

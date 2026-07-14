<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\RunsDirectoryPasswordJobs;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class LabelPrintingTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;
    use SignsKioskRequests;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kiosk.allowed_networks' => ['127.0.0.1'],
            'student-password-reset.label_printing_enabled' => true,
        ]);
    }

    private function enrolledKioskSession(): array
    {
        $kiosk = Kiosk::factory()->browserEnrolled()->create([
            'allowed_ip' => '127.0.0.1',
        ]);

        return [$kiosk, null, [config('kiosk.registration_session_kiosk_key') => $kiosk->id]];
    }

    private function kioskRequest()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function printableResetRequest(Kiosk $kiosk, array $session): PasswordResetRequest
    {
        $this->kioskRequest()->withSession($session)->get(route('kiosk.reset.index'));

        $resetRequest = PasswordResetRequest::factory()->create([
            'kiosk_id' => $kiosk->id,
            'status' => PasswordResetRequestStatus::Pending,
            'reset_mode' => 'temporary_generated',
            'kiosk_session_id' => session()->getId(),
            'pending_password_type' => PendingPasswordType::TemporaryGenerated->value,
        ]);

        $pendingPasswords = app(PendingPasswordService::class);
        $pendingPasswords->store($resetRequest, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);
        $pendingPasswords->markDisplayed($resetRequest);

        return $resetRequest->fresh();
    }

    private function postPrint(array $session, PasswordResetRequest $resetRequest): TestResponse
    {
        $session[config('kiosk.active_reset_request_session_key')] = $resetRequest->id;

        return $this->kioskRequest()->withSession($session)->post(route('kiosk.reset.print', $resetRequest));
    }

    public function test_print_route_returns_403_when_feature_flag_is_off(): void
    {
        config(['student-password-reset.label_printing_enabled' => false]);

        [$kiosk, , $session] = $this->enrolledKioskSession();
        $resetRequest = $this->printableResetRequest($kiosk, $session);

        $this->postPrint($session, $resetRequest)->assertForbidden();
    }

    public function test_successful_print_stamps_pending_password_printed_at_and_writes_audit_without_password(): void
    {
        [$kiosk, , $session] = $this->enrolledKioskSession();
        $resetRequest = $this->printableResetRequest($kiosk, $session);

        $this->postPrint($session, $resetRequest)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $resetRequest->refresh();
        $this->assertNotNull($resetRequest->pending_password_printed_at);

        $audit = AuditLog::query()->where('action', 'password.label_printed')->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $resetRequest->id, (string) ($audit->metadata['request_id'] ?? ''));
        $this->assertFalse(
            AuditLog::query()->where('metadata', 'like', '%Mint-River%')->exists(),
        );
    }

    public function test_second_print_attempt_returns_conflict(): void
    {
        [$kiosk, , $session] = $this->enrolledKioskSession();
        $resetRequest = $this->printableResetRequest($kiosk, $session);

        $this->postPrint($session, $resetRequest)->assertOk();
        $this->postPrint($session, $resetRequest)->assertStatus(409);
    }

    public function test_print_post_for_reset_request_from_different_kiosk_session_returns_403(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.0/24']]);

        [$kioskA, , $sessionA] = $this->enrolledKioskSession();
        $kioskB = Kiosk::factory()->browserEnrolled()->create([
            'allowed_ip' => '127.0.0.2',
        ]);
        $sessionB = [config('kiosk.registration_session_kiosk_key') => $kioskB->id];

        $resetRequest = PasswordResetRequest::factory()->create([
            'kiosk_id' => $kioskA->id,
            'status' => PasswordResetRequestStatus::Pending,
            'kiosk_session_id' => 'other-session-id',
            'pending_password_displayed_at' => now(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])
            ->withSession($sessionB)
            ->post(route('kiosk.reset.print', $resetRequest))
            ->assertForbidden();
    }

    public function test_force_change_at_next_login_returns_true_for_printed_request_even_when_config_disabled(): void
    {
        config([
            'student-password-reset.google_force_change_at_next_login.temporary_generated' => false,
            'student-password-reset.google_force_change_at_next_login.student_selected' => false,
        ]);

        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'pending_password_type' => PendingPasswordType::TemporaryGenerated->value,
        ]);

        app(PendingPasswordService::class)->store($request, 'ValidPass-1234', PendingPasswordType::TemporaryGenerated);

        $request->refresh()->load('activeRevision');
        $request->activeRevision->forceFill([
            'pending_password_printed_at' => now(),
            'force_change_at_next_login' => true,
        ])->save();
        app(\App\Services\PasswordRevisionService::class)->projectToRequest(
            $request,
            $request->activeRevision->fresh(),
        );

        $forceChange = null;

        $directory = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $directory->shouldReceive('resetPassword')
            ->once()
            ->with(
                Mockery::type(Student::class),
                Mockery::type('string'),
                Mockery::on(function (bool $value) use (&$forceChange): bool {
                    $forceChange = $value;

                    return $value === true;
                }),
            );

        $this->runDirectoryPasswordJob($request->id, $directory);

        $this->assertTrue($forceChange);
    }
}

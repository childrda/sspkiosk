<?php

namespace Tests\Feature;

use App\Enums\DirectoryRetryMode;
use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Exceptions\ActiveDirectoryException;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\ActiveDirectoryService;
use App\Services\AuditLogService;
use App\Services\DirectoryPasswordResetCoordinator;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsDirectoryPasswordJobs;
use Tests\TestCase;

class DirectoryPasswordResetTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;

    private function approvedRequest(array $overrides = []): PasswordResetRequest
    {
        $student = Student::factory()->registered()->create([
            'email' => $overrides['email'] ?? 'jdoe@lcps.k12.va.us',
        ]);
        unset($overrides['email']);

        $request = PasswordResetRequest::factory()->create(array_merge([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_at' => now(),
            'reset_mode' => ResetPasswordMode::TemporaryGenerated->value,
        ], $overrides));

        return $request;
    }

    private function runWith(
        PasswordResetRequest $request,
        object $google,
        object $ad,
    ): void {
        $coordinator = new DirectoryPasswordResetCoordinator(
            [$google, $ad],
            app(PendingPasswordService::class),
            app(AuditLogService::class),
            app(SlackApprovalService::class),
            app(\App\Services\PasswordRevisionService::class),
        );

        (new ResetDirectoryPasswordsJob($request->id))->handle($coordinator);
    }

    public function test_both_directories_receive_same_plaintext_password(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Same-Pass-1234-Word', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');

        $this->runWith($request, $google, $ad);

        $this->assertCount(1, $google->calls);
        $this->assertCount(1, $ad->calls);
        $this->assertSame('Same-Pass-1234-Word', $google->calls[0][1]);
        $this->assertSame('Same-Pass-1234-Word', $ad->calls[0][1]);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_sam_account_derivation_and_invalid_username(): void
    {
        $service = app(ActiveDirectoryService::class);
        $student = Student::factory()->make(['email' => 'jdoe@lcps.k12.va.us']);
        $this->assertSame('jdoe', $service->samAccountName($student));

        $long = Student::factory()->make(['email' => 'thisusernameiswaytoolong@lcps.k12.va.us']);
        try {
            $service->samAccountName($long);
            $this->fail('Expected invalid_username');
        } catch (ActiveDirectoryException $e) {
            $this->assertSame('invalid_username', $e->reason);
        }

        $illegal = Student::factory()->make(['email' => 'bad+user@lcps.k12.va.us']);
        try {
            $service->samAccountName($illegal);
            $this->fail('Expected invalid_username');
        } catch (ActiveDirectoryException $e) {
            $this->assertSame('invalid_username', $e->reason);
        }
    }

    public function test_persisted_force_change_controls_directories(): void
    {
        config([
            'active-directory.enabled' => true,
            'student-password-reset.google_force_change_at_next_login.student_selected' => false,
            'student-password-reset.google_force_change_at_next_login.temporary_generated' => true,
        ]);

        $request = $this->approvedRequest([
            'reset_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
        ]);
        app(PendingPasswordService::class)->store($request, 'ValidPass-1234', PendingPasswordType::StudentSelected);

        config(['student-password-reset.google_force_change_at_next_login.student_selected' => true]);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request, $google, $ad);

        $this->assertFalse($google->calls[0][2]);
        $this->assertFalse($ad->calls[0][2]);
    }

    public function test_temporary_generated_passes_force_change_true(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request, $google, $ad);

        $this->assertTrue($google->calls[0][2]);
        $this->assertTrue($ad->calls[0][2]);
    }

    public function test_google_success_ad_connection_failed_retries_ad_only(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('down', 'connection_failed', DirectoryRetryMode::Automatic);

        $this->runWith($request, $google, $ad);

        $this->assertCount(1, $google->calls);
        $this->assertCount(1, $ad->calls);
        $this->assertSame(PasswordResetRequestStatus::PartiallyCompleted, $request->fresh()->status);
        $this->assertTrue($request->fresh()->retry_available);
        $this->assertTrue($request->fresh()->hasEncryptedPendingPassword());

        $ad->throw = null;
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertCount(1, $google->calls);
        $this->assertCount(2, $ad->calls);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_ad_policy_rejected_marks_partially_completed_and_keeps_password(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('policy', 'policy_rejected', DirectoryRetryMode::None);

        $job = new class($request->id) extends ResetDirectoryPasswordsJob
        {
            public int $released = 0;

            public function release($delay = 0): void
            {
                $this->released++;
            }
        };

        $coordinator = new DirectoryPasswordResetCoordinator(
            [$google, $ad],
            app(PendingPasswordService::class),
            app(AuditLogService::class),
            app(SlackApprovalService::class),
            app(\App\Services\PasswordRevisionService::class),
        );
        $job->handle($coordinator);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::PartiallyCompleted, $request->status);
        $this->assertSame(0, $job->released);
        $this->assertSame('success', $request->directory_results['results']['google']['status']);
        $this->assertSame('failed', $request->directory_results['results']['active_directory']['status']);
        $this->assertSame('policy_rejected', $request->directory_results['results']['active_directory']['reason']);
        $this->assertSame('none', $request->directory_results['results']['active_directory']['retry_mode']);
        $this->assertTrue($request->hasEncryptedPendingPassword());
        $this->assertFalse($request->retry_available);
    }

    public function test_both_connection_failed_remain_approved_processing(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $google->throw = new \App\Exceptions\GoogleWorkspaceException('down', 'connection_failed', DirectoryRetryMode::Automatic);
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('down', 'connection_failed', DirectoryRetryMode::Automatic);

        $this->runWith($request, $google, $ad);

        $this->assertSame(PasswordResetRequestStatus::ApprovedProcessing, $request->fresh()->status);
    }

    public function test_permission_denied_is_manual_retry(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('denied', 'permission_denied', DirectoryRetryMode::Manual);

        $this->runWith($request, $google, $ad);

        $request->refresh();
        $this->assertSame('manual', $request->directory_results['results']['active_directory']['retry_mode']);
        $this->assertTrue($request->retry_available);
        $this->assertSame(PasswordResetRequestStatus::PartiallyCompleted, $request->status);
    }

    public function test_ad_disabled_skips_and_google_success_completes(): void
    {
        config(['active-directory.enabled' => false]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory', configured: false);

        $this->runWith($request, $google, $ad);

        $request->refresh();
        $this->assertSame(['google'], $request->directory_results['required_directories']);
        $this->assertSame('skipped', $request->directory_results['results']['active_directory']['status']);
        $this->assertSame('disabled', $request->directory_results['results']['active_directory']['reason']);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->status);
        $this->assertCount(0, $ad->calls);
    }

    public function test_planned_directories_stable_across_retries_when_config_changes(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('down', 'connection_failed', DirectoryRetryMode::Automatic);
        $this->runWith($request, $google, $ad);

        $planned = $request->fresh()->directory_results['planned_directories'];
        $required = $request->fresh()->directory_results['required_directories'];

        config(['active-directory.enabled' => false]);
        $ad->throw = null;
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertSame($planned, $request->fresh()->directory_results['planned_directories']);
        $this->assertSame($required, $request->fresh()->directory_results['required_directories']);
    }

    public function test_stale_processing_is_reclaimed(): void
    {
        config([
            'active-directory.enabled' => false,
            'directory-processing.stale_processing_minutes' => 5,
        ]);

        $request = $this->approvedRequest([
            'directory_results' => [
                'planned_directories' => ['google', 'active_directory'],
                'required_directories' => ['google'],
                'results' => [
                    'google' => [
                        'status' => 'processing',
                        'reason' => null,
                        'retry_mode' => 'none',
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'processing_started_at' => now()->subMinutes(10)->toIso8601String(),
                        'completed_at' => null,
                    ],
                    'active_directory' => [
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'retry_mode' => 'none',
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'processing_started_at' => null,
                        'completed_at' => null,
                    ],
                ],
            ],
        ]);
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);
        // store clears directory_results — restore stale snapshot after store
        $request->refresh();
        $request->forceFill([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'directory_results' => [
                'planned_directories' => ['google', 'active_directory'],
                'required_directories' => ['google'],
                'results' => [
                    'google' => [
                        'status' => 'processing',
                        'reason' => null,
                        'retry_mode' => 'none',
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'processing_started_at' => now()->subMinutes(10)->toIso8601String(),
                        'completed_at' => null,
                    ],
                    'active_directory' => [
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'retry_mode' => 'none',
                        'attempts' => 0,
                        'last_attempt_at' => null,
                        'processing_started_at' => null,
                        'completed_at' => null,
                    ],
                ],
            ],
        ])->save();

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory', configured: false);
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertCount(1, $google->calls);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_legacy_google_columns_derived_from_directory_results(): void
    {
        config(['active-directory.enabled' => false]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory', configured: false);
        $this->runWith($request, $google, $ad);

        $request->refresh();
        $this->assertTrue($request->google_reset_success);
        $this->assertNotNull($request->google_reset_attempted_at);
        $this->assertNull($request->google_error_message);
    }

    public function test_mark_printed_forbidden_for_student_selected_mode(): void
    {
        config([
            'student-password-reset.label_printing_enabled' => true,
            'kiosk.allowed_networks' => ['127.0.0.1'],
        ]);

        $kiosk = \App\Models\Kiosk::factory()->browserEnrolled()->create(['allowed_ip' => '127.0.0.1']);
        $request = PasswordResetRequest::factory()->create([
            'kiosk_id' => $kiosk->id,
            'status' => PasswordResetRequestStatus::Pending,
            'password_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
            'pending_password_type' => PendingPasswordType::StudentSelected->value,
            'pending_password_displayed_at' => now(),
            'kiosk_session_id' => 'session-1',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withSession([
                config('kiosk.registration_session_kiosk_key') => $kiosk->id,
                config('kiosk.active_reset_request_session_key') => $request->id,
            ])
            ->post(route('kiosk.reset.print', $request))
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\PasswordGeneratorService;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\RunsDirectoryPasswordJobs;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class PendingPasswordFlowTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;
    use SignsKioskRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'student-password-reset.reset_password_mode' => 'temporary_generated',
            'student-password-reset.pending_password.encryption_enabled' => true,
            'student-password-reset.slack_approval_required' => true,
            'kiosk.allowed_networks' => ['127.0.0.1'],
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

    public function test_temporary_generated_stores_pending_password_before_approval(): void
    {
        Queue::fake();

        $student = Student::factory()->registered()->create();
        $request = PasswordResetRequest::factory()->create([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::Pending,
            'reset_mode' => 'temporary_generated',
        ]);

        app(PendingPasswordService::class)->store($request, 'Blue-Oak-9999-Fern', PendingPasswordType::TemporaryGenerated);

        $this->assertTrue($request->fresh()->hasEncryptedPendingPassword());
        $this->assertNotSame('Blue-Oak-9999-Fern', $request->fresh()->encrypted_pending_password);
        Queue::assertNothingPushed();
    }

    public function test_temporary_generated_displays_pending_password_before_approval(): void
    {
        [$kiosk, , $session] = $this->enrolledKioskSession();

        $this->kioskRequest()->withSession($session)->get(route('kiosk.reset.index'));
        $sessionId = session()->getId();

        $resetRequest = PasswordResetRequest::factory()->create([
            'kiosk_id' => $kiosk->id,
            'status' => PasswordResetRequestStatus::Pending,
            'reset_mode' => 'temporary_generated',
            'kiosk_session_id' => $sessionId,
            'pending_password_type' => PendingPasswordType::TemporaryGenerated->value,
        ]);

        app(PendingPasswordService::class)->store($resetRequest, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $session[config('kiosk.active_reset_request_session_key')] = $resetRequest->id;

        $this->kioskRequest()->withSession($session)
            ->get(route('kiosk.reset.pending-password', $resetRequest))
            ->assertOk()
            ->assertSee('Mint-River-4321-Sky')
            ->assertSee('will not work yet', false);

        $this->kioskRequest()->withSession($session)
            ->get(route('kiosk.reset.pending-password', $resetRequest))
            ->assertOk()
            ->assertDontSee('Mint-River-4321-Sky');
    }

    public function test_google_reset_job_runs_only_after_approval_and_deletes_pending_password(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'reset_mode' => 'temporary_generated',
            'pending_password_type' => PendingPasswordType::TemporaryGenerated->value,
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $directory = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $directory->shouldReceive('resetPassword')
            ->once()
            ->withArgs(function ($student, $password, $forceChange) {
                return $password === 'Mint-River-4321-Sky' && $forceChange === true;
            });

        $this->runDirectoryPasswordJob($request->id, $directory);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->status);
        $this->assertFalse($request->hasEncryptedPendingPassword());
        $this->assertNotNull($request->pending_password_deleted_at);
    }

    public function test_denial_deletes_encrypted_pending_password(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
        ]);

        app(PendingPasswordService::class)->store($request, 'Secret-Word-0000-Test', PendingPasswordType::TemporaryGenerated);
        app(PendingPasswordService::class)->delete($request, 'denial');

        $this->assertFalse($request->fresh()->hasEncryptedPendingPassword());
        $this->assertNotNull($request->fresh()->pending_password_deleted_at);
    }

    public function test_student_selected_mode_validates_and_encrypts_password(): void
    {
        Queue::fake();
        config(['student-password-reset.reset_password_mode' => 'student_selected_pending_approval']);

        [$kiosk, , $session] = $this->enrolledKioskSession();
        $student = Student::factory()->registered()->create(['email' => 'alex@students.example.org', 'name' => 'Alex Johnson']);

        $session[config('kiosk.reset_session_student_key')] = $student->id;
        $photo = $student->photos()->create([
            'type' => \App\Enums\StudentPhotoType::ResetRequest,
            'storage_path' => 'student-photos/'.$student->id.'/reset.jpg',
        ]);

        $session[config('kiosk.reset_session_photo_key')] = $photo->id;
        $session[config('kiosk.reset_session_questions_key')] = [['id' => 1, 'question' => 'Q?']];
        $session[config('kiosk.reset_session_challenge_score_key')] = 3;

        $this->kioskRequest()->withSession($session)
            ->post(route('kiosk.reset.password.store'), [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->kioskRequest()->withSession($session)
            ->post(route('kiosk.reset.password.store'), [
                'password' => 'ValidPass-1234',
                'password_confirmation' => 'ValidPass-1234',
            ])
            ->assertRedirect();

        $request = PasswordResetRequest::query()->first();
        $this->assertSame('student_selected_pending_approval', $request->reset_mode);
        $this->assertTrue($request->hasEncryptedPendingPassword());
        $this->assertSame(
            'ValidPass-1234',
            app(PendingPasswordService::class)->decrypt($request),
        );
        Queue::assertPushed(\App\Jobs\SendSlackResetApprovalJob::class);
    }

    public function test_slack_message_does_not_contain_password(): void
    {
        config([
            'slack.bot_token' => 'xoxb-test',
            'slack.reset_channel_id' => 'C123',
            'slack.approver_usergroup_id' => 'S1',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'slack.com/api/chat.postMessage' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '1.1']),
            'slack.com/api/usergroups.users.list*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'users' => []]),
        ]);

        $request = PasswordResetRequest::factory()->create([
            'reset_mode' => 'temporary_generated',
            'pending_password_type' => PendingPasswordType::TemporaryGenerated->value,
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        app(SlackApprovalService::class)->sendApprovalMessage($request);

        \Illuminate\Support\Facades\Http::assertSent(function ($httpRequest) {
            $body = json_encode($httpRequest->data());

            return ! str_contains($body, 'Mint-River-4321-Sky');
        });
    }

    public function test_audit_logs_do_not_contain_password(): void
    {
        $request = PasswordResetRequest::factory()->create();
        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->assertFalse(
            AuditLog::query()->where('metadata', 'like', '%Mint-River%')->exists(),
        );
    }

    public function test_job_constructor_only_stores_request_id(): void
    {
        $job = new ResetDirectoryPasswordsJob(42);
        $serialized = serialize($job);

        $this->assertStringNotContainsString('Mint-River', $serialized);
        $this->assertStringContainsString('42', $serialized);
    }

    public function test_invalid_reset_password_mode_blocks_kiosk_reset(): void
    {
        config(['student-password-reset.reset_password_mode' => 'invalid_mode']);

        $this->get(route('kiosk.reset.index'))->assertRedirect(route('kiosk.reset.unavailable'));
    }

    public function test_student_selected_google_force_change_defaults_false(): void
    {
        config([
            'student-password-reset.google_force_change_at_next_login.student_selected' => false,
            'student-password-reset.google_force_change_at_next_login.temporary_generated' => true,
        ]);

        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'pending_password_type' => PendingPasswordType::StudentSelected->value,
        ]);

        app(PendingPasswordService::class)->store($request, 'ValidPass-1234', PendingPasswordType::StudentSelected);

        $directory = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $directory->shouldReceive('resetPassword')
            ->once()
            ->withArgs(fn ($student, $password, $forceChange) => $forceChange === false);

        $this->runDirectoryPasswordJob($request->id, $directory);
    }
}

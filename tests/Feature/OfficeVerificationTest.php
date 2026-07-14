<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use Tests\Support\RunsDirectoryPasswordJobs;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\GoogleWorkspaceDirectoryService;
use App\Services\OfficeVerificationService;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OfficeVerificationTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    private function officeVerificationRequest(array $attributes = []): PasswordResetRequest
    {
        return PasswordResetRequest::factory()->create(array_merge([
            'status' => PasswordResetRequestStatus::NeedsOfficeVerification,
            'escalated_at' => now()->subHour(),
            'escalated_by_slack_user_id' => 'U_TECH',
            'office_verification_expires_at' => now()->addHours(24),
            'slack_channel_id' => 'C123',
            'slack_message_ts' => '1234.5678',
        ], $attributes));
    }

    private function postSlackOffice(PasswordResetRequest $request): void
    {
        config([
            'slack.signing_secret' => 'test_signing_secret',
            'slack.approver_usergroup_id' => 'S_APPROVERS',
            'slack.bot_token' => 'xoxb-test',
            'student-password-reset.office_verification_allowed' => true,
        ]);

        Http::fake([
            'slack.com/api/usergroups.users.list*' => Http::response(['ok' => true, 'users' => ['U_TECH']]),
            'slack.com/api/chat.update' => Http::response(['ok' => true]),
        ]);

        $body = 'payload='.rawurlencode(json_encode([
            'type' => 'block_actions',
            'user' => ['id' => 'U_TECH'],
            'actions' => [
                [
                    'action_id' => SlackApprovalService::ACTION_OFFICE,
                    'value' => (string) $request->id,
                ],
            ],
        ]));

        $timestamp = (string) time();
        $secret = 'test_signing_secret';
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);

        $this->call(
            'POST',
            route('slack.interactions'),
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' => $signature,
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ]),
            $body,
        )->assertOk();
    }

    public function test_slack_escalation_sets_escalated_at_and_leaves_denied_at_null(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->addMinutes(10),
            'slack_channel_id' => 'C123',
            'slack_message_ts' => '1234.5678',
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->postSlackOffice($request);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::NeedsOfficeVerification, $request->status);
        $this->assertNotNull($request->escalated_at);
        $this->assertSame('U_TECH', $request->escalated_by_slack_user_id);
        $this->assertNull($request->denied_at);
        $this->assertNull($request->denied_by_slack_user_id);
        $this->assertNull($request->denial_reason);
        $this->assertNotNull($request->office_verification_expires_at);
    }

    public function test_slack_escalation_deletes_pending_password(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->addMinutes(10),
            'slack_channel_id' => 'C123',
            'slack_message_ts' => '1234.5678',
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->postSlackOffice($request);

        $request->refresh();
        $this->assertFalse($request->hasEncryptedPendingPassword());
        $this->assertNotNull($request->pending_password_deleted_at);
    }

    public function test_admin_verify_dispatches_job_and_flashes_password(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest();

        Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);

        $response = $this->actingAs($admin)->post(route('admin.requests.office-verify', $request), [
            'notes' => 'Matched photo ID',
        ]);

        $response->assertRedirect(route('admin.requests.show', $request));
        $response->assertSessionHas('office_password');

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::ApprovedProcessing, $request->status);
        $this->assertTrue($request->hasEncryptedPendingPassword());
        $this->assertNotNull($request->office_verified_at);
        $this->assertSame($admin->id, $request->office_verified_by_user_id);
        $this->assertNull($request->google_reset_attempted_at);

        Queue::assertPushed(ResetDirectoryPasswordsJob::class, fn (ResetDirectoryPasswordsJob $job): bool => $job->passwordResetRequestId === $request->id);
    }

    public function test_verify_forces_change_password_at_next_login_even_when_config_disabled(): void
    {
        Queue::fake();

        config([
            'student-password-reset.google_force_change_at_next_login.temporary_generated' => false,
            'student-password-reset.google_force_change_at_next_login.student_selected' => false,
        ]);

        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest();
        $forceChange = null;

        Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);

        $this->actingAs($admin)->post(route('admin.requests.office-verify', $request));

        $request->refresh();

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

    public function test_verify_on_wrong_status_returns_conflict(): void
    {
        $admin = $this->adminUser();
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.requests.office-verify', $request))
            ->assertRedirect(route('admin.requests.show', $request))
            ->assertSessionHas('error');
    }

    public function test_verify_on_expired_escalation_is_blocked(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest([
            'office_verification_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.requests.office-verify', $request))
            ->assertRedirect(route('admin.requests.show', $request))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertFalse($request->fresh()->hasEncryptedPendingPassword());
    }

    public function test_verify_blocks_when_queue_depth_exceeds_threshold(): void
    {
        Queue::fake();
        config(['student-password-reset.office_verification_max_queue_depth' => 1]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest();

        $this->actingAs($admin)
            ->post(route('admin.requests.office-verify', $request))
            ->assertRedirect(route('admin.requests.show', $request))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertFalse($request->fresh()->hasEncryptedPendingPassword());
    }

    public function test_reject_marks_denied_without_disabling_student_resets(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $student = Student::factory()->registered()->create(['reset_enabled' => true]);
        $request = $this->officeVerificationRequest(['student_id' => $student->id]);

        Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);

        $this->actingAs($admin)->post(route('admin.requests.office-reject', $request), [
            'reason' => 'Could not verify identity',
        ])->assertRedirect(route('admin.requests.show', $request));

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::Denied, $request->status);
        $this->assertSame('Could not verify identity', $request->denial_reason);
        $this->assertNull($request->denied_by_slack_user_id);
        $this->assertTrue($student->fresh()->reset_enabled);

        Queue::assertNothingPushed();
    }

    public function test_retry_on_failed_request_mints_password_and_dispatches_job(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Failed,
            'google_reset_attempted_at' => now(),
            'google_reset_success' => false,
            'google_error_message' => 'Google password reset failed.',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.requests.retry-reset', $request));

        $response->assertRedirect(route('admin.requests.show', $request));
        $response->assertSessionHas('office_password');

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::ApprovedProcessing, $request->status);
        $this->assertNull($request->google_reset_attempted_at);
        $this->assertTrue($request->hasEncryptedPendingPassword());

        Queue::assertPushed(ResetDirectoryPasswordsJob::class);
    }

    public function test_retry_on_non_failed_status_is_blocked(): void
    {
        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest();

        $this->actingAs($admin)
            ->post(route('admin.requests.retry-reset', $request))
            ->assertRedirect(route('admin.requests.show', $request))
            ->assertSessionHas('error');
    }

    public function test_double_verify_results_in_single_job_dispatch(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        $request = $this->officeVerificationRequest();

        Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);

        $this->actingAs($admin)->post(route('admin.requests.office-verify', $request));
        $this->actingAs($admin)->post(route('admin.requests.office-verify', $request->fresh()));

        Queue::assertPushed(ResetDirectoryPasswordsJob::class, 1);
    }

    public function test_expire_requests_command_expires_stale_office_verification(): void
    {
        $stale = $this->officeVerificationRequest([
            'office_verification_expires_at' => now()->subMinute(),
        ]);
        $fresh = $this->officeVerificationRequest([
            'office_verification_expires_at' => now()->addHour(),
        ]);

        $this->artisan('ssp:expire-requests')->assertSuccessful();

        $this->assertSame(PasswordResetRequestStatus::Expired, $stale->fresh()->status);
        $this->assertSame(PasswordResetRequestStatus::NeedsOfficeVerification, $fresh->fresh()->status);
    }

    public function test_non_admin_cannot_access_office_routes(): void
    {
        $user = User::factory()->create();
        $request = $this->officeVerificationRequest();

        $this->actingAs($user)->post(route('admin.requests.office-verify', $request))->assertForbidden();
        $this->actingAs($user)->post(route('admin.requests.office-reject', $request), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.requests.retry-reset', $request))->assertForbidden();
    }

    public function test_escalated_request_can_be_verified_to_completed(): void
    {
        Queue::fake();

        $student = Student::factory()->registered()->create();
        $request = PasswordResetRequest::factory()->create([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->addMinutes(10),
            'slack_channel_id' => 'C123',
            'slack_message_ts' => '1234.5678',
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->postSlackOffice($request);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::NeedsOfficeVerification, $request->status);

        Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);

        $this->actingAs($this->adminUser())->post(route('admin.requests.office-verify', $request), [
            'notes' => 'ID checked',
        ]);

        $request->refresh();

        $directory = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $directory->shouldReceive('resetPassword')->once();

        $this->runDirectoryPasswordJob($request->id, $directory);

        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
        $this->assertTrue($request->fresh()->google_reset_success);
    }
}

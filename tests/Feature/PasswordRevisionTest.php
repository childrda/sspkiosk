<?php

namespace Tests\Feature;

use App\Enums\DirectoryRetryMode;
use App\Enums\PasswordOrigin;
use App\Enums\PasswordResetRequestStatus;
use App\Enums\PasswordResetRevisionStatus;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Exceptions\ActiveDirectoryException;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\PasswordResetRevision;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DirectoryPasswordResetCoordinator;
use App\Services\PasswordRevisionService;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\RunsDirectoryPasswordJobs;
use Tests\TestCase;

class PasswordRevisionTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    private function approvedRequest(array $overrides = []): PasswordResetRequest
    {
        $student = Student::factory()->registered()->create([
            'email' => $overrides['email'] ?? 'jdoe@lcps.k12.va.us',
        ]);
        unset($overrides['email']);

        return PasswordResetRequest::factory()->create(array_merge([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_at' => now(),
            'reset_mode' => ResetPasswordMode::TemporaryGenerated->value,
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    private function runWith(PasswordResetRequest $request, object $google, object $ad): void
    {
        $coordinator = new DirectoryPasswordResetCoordinator(
            [$google, $ad],
            app(PendingPasswordService::class),
            app(AuditLogService::class),
            app(SlackApprovalService::class),
            app(PasswordRevisionService::class),
        );

        (new ResetDirectoryPasswordsJob($request->id))->handle($coordinator);
    }

    private function partiallyCompletedPolicyRejected(): PasswordResetRequest
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store(
            $request,
            'Secret-Policy-9999-Word',
            PendingPasswordType::TemporaryGenerated,
        );

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('policy', 'policy_rejected', DirectoryRetryMode::None);

        $this->runWith($request, $google, $ad);

        return $request->fresh(['activeRevision', 'revisions']);
    }

    private function partiallyCompletedPermissionDenied(): PasswordResetRequest
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store(
            $request,
            'Secret-Policy-9999-Word',
            PendingPasswordType::TemporaryGenerated,
        );

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $ad->throw = new ActiveDirectoryException('denied', 'permission_denied', DirectoryRetryMode::Manual);

        $this->runWith($request, $google, $ad);

        return $request->fresh(['activeRevision', 'revisions']);
    }

    public function test_existing_requests_are_backfilled_into_revision_one(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
            'password_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
            'password_origin' => PasswordOrigin::StudentSelected->value,
            'force_change_at_next_login' => false,
            'retry_available' => false,
            'encrypted_pending_password' => 'encrypted-blob',
            'pending_password_expires_at' => now()->addMinutes(10),
            'directory_results' => [
                'planned_directories' => ['google'],
                'required_directories' => ['google'],
                'results' => [],
            ],
        ]);

        // Simulate migration backfill for a pre-revision request row.
        \Illuminate\Support\Facades\DB::table('password_reset_revisions')->insert([
            'password_reset_request_id' => $request->id,
            'revision_number' => 1,
            'password_mode' => $request->password_mode,
            'password_origin' => $request->password_origin,
            'force_change_at_next_login' => $request->force_change_at_next_login,
            'encrypted_pending_password' => $request->encrypted_pending_password,
            'pending_password_expires_at' => $request->pending_password_expires_at,
            'directory_results' => json_encode($request->directory_results),
            'retry_available' => false,
            'status' => PasswordResetRevisionStatus::Active->value,
            'active_for_request_id' => $request->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('password_reset_revisions', [
            'password_reset_request_id' => $request->id,
            'revision_number' => 1,
            'password_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
            'password_origin' => PasswordOrigin::StudentSelected->value,
            'force_change_at_next_login' => 0,
            'encrypted_pending_password' => 'encrypted-blob',
            'status' => PasswordResetRevisionStatus::Active->value,
            'active_for_request_id' => $request->id,
        ]);

        $fresh = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_at' => now(),
        ]);
        app(PendingPasswordService::class)->store(
            $fresh,
            'Fresh-Store-1234-Word',
            PendingPasswordType::TemporaryGenerated,
        );

        $revision = $fresh->fresh()->activeRevision;
        $this->assertNotNull($revision);
        $this->assertSame(1, $revision->revision_number);
        $this->assertTrue($revision->hasEncryptedPendingPassword());
        $this->assertSame(PasswordOrigin::TemporaryGenerated->value, $revision->password_origin);
    }

    public function test_coordinator_reads_active_revision_not_divergent_request_row(): void
    {
        config(['active-directory.enabled' => true]);

        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store(
            $request,
            'Rev-Wins-1234-Pass',
            PendingPasswordType::TemporaryGenerated,
        );

        $revision = $request->fresh()->activeRevision;
        $this->assertNotNull($revision);
        $this->assertTrue((bool) $revision->force_change_at_next_login);

        $request->forceFill(['force_change_at_next_login' => false])->save();

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertTrue($google->calls[0][2]);
        $this->assertTrue($ad->calls[0][2]);
    }

    public function test_policy_rejected_hides_retry_and_shows_replace(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('admin.requests.show', $request));

        $response->assertOk();
        $response->assertSee('Replace password in all directories');
        $response->assertDontSee('Retry failed directories', false);
        $this->assertFalse($request->retry_available);
    }

    public function test_permission_denied_shows_retry(): void
    {
        $request = $this->partiallyCompletedPermissionDenied();
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('admin.requests.show', $request));

        $response->assertOk();
        $response->assertSee('Retry failed directories');
        $this->assertTrue($request->retry_available);
    }

    public function test_retry_uses_active_revision_and_skips_successful_google(): void
    {
        config(['active-directory.enabled' => true]);
        Queue::fake();

        $request = $this->partiallyCompletedPermissionDenied();
        $revisionId = $request->activeRevision->id;
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.requests.retry-reset', $request))
            ->assertRedirect();

        Queue::assertPushed(ResetDirectoryPasswordsJob::class);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::ApprovedProcessing, $request->status);
        $this->assertSame($revisionId, $request->activeRevision->id);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertCount(0, $google->calls);
        $this->assertCount(1, $ad->calls);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_replacement_requires_confirmation_and_reason(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.requests.start-replacement', $request), [
                'confirmation' => 'replace password',
                'reason' => 'policy',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->post(route('admin.requests.start-replacement', $request), [
                'confirmation' => 'REPLACE PASSWORD',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_replacement_creates_revision_two_supersedes_one_and_resets_directories(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $rev1 = $request->activeRevision;
        $this->assertTrue($rev1->hasEncryptedPendingPassword());
        $oldResults = $rev1->directory_results;

        $admin = $this->adminUser();
        $this->actingAs($admin)
            ->post(route('admin.requests.start-replacement', $request), [
                'confirmation' => 'REPLACE PASSWORD',
                'reason' => 'AD policy rejected student password',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $request->refresh()->load(['revisions', 'activeRevision']);
        $this->assertSame(PasswordResetRequestStatus::AwaitingPasswordReselection, $request->status);
        $this->assertCount(2, $request->revisions);

        $rev1->refresh();
        $this->assertSame(PasswordResetRevisionStatus::Superseded, $rev1->status);
        $this->assertNotNull($rev1->superseded_at);
        $this->assertFalse($rev1->hasEncryptedPendingPassword());
        $this->assertSame(
            $oldResults['results']['google']['status'],
            $rev1->directory_results['results']['google']['status'],
        );

        $rev2 = $request->activeRevision;
        $this->assertSame(2, $rev2->revision_number);
        $this->assertSame('pending', $rev2->directory_results['results']['google']['status']);
        $this->assertSame('pending', $rev2->directory_results['results']['active_directory']['status']);
        $this->assertFalse($rev2->hasEncryptedPendingPassword());
    }

    public function test_reselection_stores_password_and_revision_two_success_completes(): void
    {
        config([
            'active-directory.enabled' => true,
            'student-password-reset.reset_password_mode' => ResetPasswordMode::StudentSelectedPendingApproval->value,
        ]);

        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();

        app(PasswordRevisionService::class)->startPasswordReplacement(
            $request,
            $admin,
            'Need new password',
            'REPLACE PASSWORD',
        );

        $request->refresh();
        app(PasswordRevisionService::class)->storeReselectionPassword($request, 'New-Choice-4321-Word!');

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::Pending, $request->status);
        $this->assertTrue($request->activeRevision->hasEncryptedPendingPassword());

        $request->forceFill([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_at' => now(),
        ])->save();

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request->fresh(), $google, $ad);

        $this->assertCount(1, $google->calls);
        $this->assertCount(1, $ad->calls);
        $this->assertSame('New-Choice-4321-Word!', $google->calls[0][1]);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
        $this->assertCount(2, $request->fresh()->revisions);
        $this->assertSame(
            PasswordResetRevisionStatus::Completed,
            $request->fresh()->revisions()->where('revision_number', 2)->first()->status,
        );

        $html = $this->actingAs($admin)->get(route('admin.requests.show', $request->fresh()))->getContent();
        $this->assertStringNotContainsString('New-Choice-4321-Word!', $html);
        $this->assertStringNotContainsString('Secret-Policy-9999-Word', $html);

        $this->assertFalse(
            AuditLog::query()->where('metadata', 'like', '%New-Choice-4321-Word!%')->exists(),
        );
    }

    public function test_replacement_rate_limit_is_enforced(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();
        $revisions = app(PasswordRevisionService::class);

        config(['student-password-reset.max_replacement_revisions' => 1]);

        $revisions->startPasswordReplacement($request, $admin, 'first', 'REPLACE PASSWORD');
        $request->refresh()->forceFill([
            'status' => PasswordResetRequestStatus::PartiallyCompleted,
        ])->save();
        $request->activeRevision->forceFill([
            'directory_results' => [
                'planned_directories' => ['google', 'active_directory'],
                'required_directories' => ['google', 'active_directory'],
                'results' => [
                    'google' => ['status' => 'success', 'retry_mode' => 'none'],
                    'active_directory' => ['status' => 'failed', 'reason' => 'policy_rejected', 'retry_mode' => 'none'],
                ],
            ],
        ])->save();

        $this->expectException(ConflictHttpException::class);
        $revisions->startPasswordReplacement($request->fresh(), $admin, 'second', 'REPLACE PASSWORD');
    }

    public function test_two_active_revisions_are_impossible_via_db_guard(): void
    {
        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store(
            $request,
            'Only-One-Active-9999',
            PendingPasswordType::TemporaryGenerated,
        );

        try {
            PasswordResetRevision::query()->create([
                'password_reset_request_id' => $request->id,
                'revision_number' => 2,
                'status' => PasswordResetRevisionStatus::Active,
                'active_for_request_id' => $request->id,
                'retry_available' => false,
            ]);
            $this->fail('Expected unique constraint violation for second active revision');
        } catch (QueryException|\Illuminate\Database\UniqueConstraintViolationException $exception) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->whereNotNull('active_for_request_id')
            ->count());
    }

    public function test_unique_active_violation_through_service_returns_clean_conflict(): void
    {
        $request = $this->approvedRequest();
        app(PendingPasswordService::class)->store(
            $request,
            'Only-One-Active-9999',
            PendingPasswordType::TemporaryGenerated,
        );

        $service = app(PasswordRevisionService::class);
        $method = new \ReflectionMethod($service, 'createActiveRevision');
        $method->setAccessible(true);

        try {
            $method->invoke($service, [
                'password_reset_request_id' => $request->id,
                'revision_number' => 2,
                'status' => PasswordResetRevisionStatus::Active,
                'active_for_request_id' => $request->id,
                'retry_available' => false,
            ]);
            $this->fail('Expected ConflictHttpException');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(
                'This request was already updated by someone else. Refresh and try again.',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(
                \Illuminate\Database\UniqueConstraintViolationException::class,
                $exception->getPrevious(),
            );
        }
    }

    public function test_office_revision_create_unique_race_returns_clean_conflict(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $service = app(PasswordRevisionService::class);

        $service->supersedeRevision($request->activeRevision);

        // Another concurrent writer already claimed the single-active unique slot.
        PasswordResetRevision::query()->create([
            'password_reset_request_id' => $request->id,
            'revision_number' => 2,
            'status' => PasswordResetRevisionStatus::Failed,
            'superseded_at' => now(),
            'active_for_request_id' => $request->id,
            'retry_available' => false,
        ]);

        try {
            $service->createOfficeGeneratedRevision($request->fresh(), 'Office-Race-1234-Word', true);
            $this->fail('Expected ConflictHttpException from prr_active_unique');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(
                'This request was already updated by someone else. Refresh and try again.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->whereNotNull('active_for_request_id')
            ->count());
    }

    public function test_office_fallback_creates_temporary_revision_with_force_change(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();
        app(PasswordRevisionService::class)->startPasswordReplacement(
            $request,
            $admin,
            'student unavailable',
            'REPLACE PASSWORD',
        );

        $plain = 'Office-Temp-5555-Word';
        $revision = app(PasswordRevisionService::class)->createOfficeGeneratedRevision(
            $request->fresh(),
            $plain,
            true,
        );

        $this->assertSame(PasswordOrigin::OfficeGeneratedTemporary->value, $revision->password_origin);
        $this->assertTrue($revision->force_change_at_next_login);
        $this->assertTrue($request->fresh()->superseded_student_selected_password);
        $this->assertSame(3, $revision->revision_number);
    }

    public function test_cancel_deletes_plaintext_and_blocks_execution(): void
    {
        $request = $this->partiallyCompletedPermissionDenied();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.requests.cancel', $request), [
                'confirmation' => 'CANCEL REQUEST',
                'reason' => 'Abandoned after staff review',
            ])
            ->assertRedirect();

        $request->refresh()->load('revisions');
        $this->assertSame(PasswordResetRequestStatus::Denied, $request->status);
        $this->assertFalse($request->hasEncryptedPendingPassword());
        $this->assertFalse($request->retry_available);

        $google = $this->makeDirectoryFake('google');
        $ad = $this->makeDirectoryFake('active_directory');
        $this->runWith($request, $google, $ad);
        $this->assertCount(0, $google->calls);
        $this->assertCount(0, $ad->calls);
    }

    public function test_expired_partially_completed_becomes_terminal_split_case(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $revision = $request->activeRevision;
        $revision->forceFill(['pending_password_expires_at' => now()->subMinute()])->save();
        app(PasswordRevisionService::class)->projectToRequest($request, $revision->fresh());

        Artisan::call('ssp:expire-requests');

        $request->refresh()->load('revisions');
        $this->assertSame(PasswordResetRequestStatus::Failed, $request->status);
        $this->assertFalse($request->retry_available);
        $this->assertFalse($request->hasEncryptedPendingPassword());
        $this->assertSame('success', $request->directory_results['results']['google']['status']);
        $this->assertSame('failed', $request->directory_results['results']['active_directory']['status']);
        $this->assertSame(
            PasswordResetRevisionStatus::Failed,
            $request->revisions()->where('revision_number', 1)->first()->status,
        );
    }

    public function test_simultaneous_replacement_and_cancel_yield_one_winner(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();
        $revisions = app(PasswordRevisionService::class);

        $revisions->cancel($request, $admin, 'Need to abandon', 'CANCEL REQUEST');

        try {
            $revisions->startPasswordReplacement($request->fresh(), $admin, 'late replace', 'REPLACE PASSWORD');
            $this->fail('Expected conflict after cancel');
        } catch (ConflictHttpException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertSame(0, PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->whereNotNull('active_for_request_id')
            ->count());
        $this->assertSame(PasswordResetRequestStatus::Denied, $request->fresh()->status);
    }

    public function test_duplicate_replacement_submissions_create_only_one_active_revision(): void
    {
        $request = $this->partiallyCompletedPolicyRejected();
        $admin = $this->adminUser();
        $revisions = app(PasswordRevisionService::class);

        $revisions->startPasswordReplacement($request, $admin, 'first', 'REPLACE PASSWORD');

        try {
            $revisions->startPasswordReplacement($request->fresh(), $admin, 'second', 'REPLACE PASSWORD');
            $this->fail('Expected conflict on second replacement');
        } catch (ConflictHttpException $exception) {
            $this->assertNotEmpty($exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
            $this->assertStringNotContainsString('prr_active_unique', $exception->getMessage());
        }

        $this->assertSame(1, PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->where('status', PasswordResetRevisionStatus::Active)
            ->whereNotNull('active_for_request_id')
            ->count());
        $this->assertSame(2, PasswordResetRevision::query()
            ->where('password_reset_request_id', $request->id)
            ->count());
    }
}

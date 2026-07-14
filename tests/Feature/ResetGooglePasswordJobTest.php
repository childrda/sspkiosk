<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\PendingPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\RunsDirectoryPasswordJobs;
use Tests\TestCase;

class ResetGooglePasswordJobTest extends TestCase
{
    use RefreshDatabase;
    use RunsDirectoryPasswordJobs;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_resets_password_only_when_approved_processing(): void
    {
        $student = Student::factory()->registered()->create();
        $request = PasswordResetRequest::factory()->create([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_at' => now(),
        ]);

        app(PendingPasswordService::class)->store($request, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->runDirectoryPasswordJob($request->id);

        $request->refresh();
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->status);
        $this->assertTrue($request->google_reset_success);
    }

    public function test_job_skips_when_not_approved_processing(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
        ]);

        $google = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $google->shouldNotReceive('resetPassword');

        $this->runDirectoryPasswordJob($request->id, $google);

        $this->assertNull($request->fresh()->google_reset_attempted_at);
    }

    public function test_job_is_idempotent(): void
    {
        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Completed,
            'google_reset_attempted_at' => now(),
            'google_reset_success' => true,
            'directory_results' => [
                'planned_directories' => ['google'],
                'required_directories' => ['google'],
                'results' => [
                    'google' => [
                        'status' => 'success',
                        'reason' => null,
                        'retry_mode' => 'none',
                        'attempts' => 1,
                        'last_attempt_at' => now()->toIso8601String(),
                        'processing_started_at' => null,
                        'completed_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ]);

        $google = Mockery::mock(\App\Contracts\DirectoryPasswordResetter::class);
        $google->shouldNotReceive('resetPassword');

        $this->runDirectoryPasswordJob($request->id, $google);

        $this->assertTrue($request->fresh()->google_reset_success);
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);
    }

    public function test_new_job_class_exists(): void
    {
        $this->assertTrue(class_exists(ResetDirectoryPasswordsJob::class));
    }
}

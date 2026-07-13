<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Jobs\ResetGooglePasswordJob;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Enums\StudentPhotoType;
use App\Models\StudentPhoto;
use App\Models\User;
use App\Services\GoogleWorkspaceDirectoryService;
use App\Services\KioskCredentialService;
use App\Services\PasswordGeneratorService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    public function test_expired_request_cannot_be_approved_via_slack(): void
    {
        Queue::fake();

        config([
            'slack.signing_secret' => 'test_signing_secret',
            'slack.approver_usergroup_id' => 'S_APPROVERS',
            'slack.bot_token' => 'xoxb-test',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'slack.com/api/usergroups.users.list*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'users' => ['U_TECH']]),
            'slack.com/api/chat.update' => \Illuminate\Support\Facades\Http::response(['ok' => true]),
        ]);

        $resetRequest = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $body = 'payload='.rawurlencode(json_encode([
            'type' => 'block_actions',
            'user' => ['id' => 'U_TECH'],
            'actions' => [
                [
                    'action_id' => SlackApprovalService::ACTION_APPROVE,
                    'value' => (string) $resetRequest->id,
                ],
            ],
        ]));

        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, 'test_signing_secret');

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
        )->assertOk()->assertJsonPath('text', 'This request has expired.');

        $this->assertSame(PasswordResetRequestStatus::Expired, $resetRequest->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_reset_disabled_student_receives_generic_lookup_failure(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();
        $kiosk = Kiosk::factory()->enrolled()->create([
            'secret_hash' => $credentials->encryptSecret($secret),
            'allowed_ip' => '127.0.0.1',
            'last_seen_at' => now(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders($this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/bind-session'))
            ->post(route('kiosk.bind-session'));

        $student = Student::factory()->registered()->create([
            'email' => 'disabled@students.example.org',
            'reset_enabled' => false,
        ]);

        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withSession([config('kiosk.registration_session_kiosk_key') => $kiosk->id])
            ->withHeaders($headers)
            ->post(route('kiosk.reset.lookup'), ['identifier' => $student->email])
            ->assertRedirect()
            ->assertSessionHas('error', config('student-password-reset.reset_lookup_failure_message'));
    }

    public function test_no_http_route_directly_resets_google_password(): void
    {
        $forbiddenFragments = [
            'resetPassword',
            'ResetGooglePassword',
            'google/password',
            'workspace/password',
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $action = $route->getActionName();

            foreach ($forbiddenFragments as $fragment) {
                $this->assertStringNotContainsString(
                    strtolower($fragment),
                    strtolower($uri.' '.$action),
                    'Route may expose direct password reset: '.$uri,
                );
            }
        }
    }

    public function test_google_reset_job_does_not_log_temporary_password(): void
    {
        $password = 'Mint-River-4321-Sky';
        $leaked = false;

        Log::listen(function ($message) use (&$leaked, $password): void {
            $haystack = $message->message.json_encode($message->context ?? []);

            if (str_contains($haystack, $password)) {
                $leaked = true;
            }
        });

        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
        ]);

        app(\App\Services\PendingPasswordService::class)->store(
            $request,
            $password,
            \App\Enums\PendingPasswordType::TemporaryGenerated,
        );

        $directory = $this->mock(GoogleWorkspaceDirectoryService::class);
        $directory->shouldReceive('resetPassword')->once();

        (new ResetGooglePasswordJob($request->id))->handle(
            $directory,
            app(\App\Services\PendingPasswordService::class),
            app(\App\Services\AuditLogService::class),
            app(SlackApprovalService::class),
        );

        $this->assertFalse($leaked);
    }

    public function test_audit_logs_do_not_store_temporary_password(): void
    {
        $request = PasswordResetRequest::factory()->create();

        app(\App\Services\PendingPasswordService::class)->store(
            $request,
            'Mint-River-4321-Sky',
            \App\Enums\PendingPasswordType::TemporaryGenerated,
        );

        $this->assertFalse(
            AuditLog::query()
                ->where('metadata', 'like', '%Mint-River%')
                ->exists(),
        );
    }

    public function test_admin_photo_endpoint_rejects_unsafe_paths(): void
    {
        $admin = User::factory()->admin()->create();
        $student = Student::factory()->create();
        $photo = StudentPhoto::query()->create([
            'student_id' => $student->id,
            'type' => StudentPhotoType::Registration,
            'storage_path' => '../outside/student-photos/evil.jpg',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.photos.show', $photo))
            ->assertNotFound();
    }

    public function test_security_headers_are_present_on_web_responses(): void
    {
        $response = $this->get(route('register.index'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}

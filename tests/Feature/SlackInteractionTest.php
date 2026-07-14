<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SlackInteractionTest extends TestCase
{
    use RefreshDatabase;

    private function signedHeaders(string $body): array
    {
        $timestamp = (string) time();
        $secret = 'test_signing_secret';
        config(['slack.signing_secret' => $secret]);

        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);

        return [
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSlackPayload(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = 'payload='.rawurlencode(json_encode($payload));

        return $this->call(
            'POST',
            route('slack.interactions'),
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders($body)),
            $body,
        );
    }

    public function test_rejects_invalid_slack_signature(): void
    {
        config(['slack.signing_secret' => 'test_signing_secret']);

        $this->post(route('slack.interactions'), ['payload' => '{}'], [
            'X-Slack-Request-Timestamp' => (string) time(),
            'X-Slack-Signature' => 'v0=invalid',
        ])->assertForbidden();
    }

    public function test_unauthorized_slack_user_cannot_approve(): void
    {
        config([
            'slack.signing_secret' => 'test_signing_secret',
            'slack.approver_usergroup_id' => 'S_APPROVERS',
        ]);

        Http::fake([
            'slack.com/api/usergroups.users.list*' => Http::response(['ok' => true, 'users' => ['U_OTHER']]),
        ]);

        $request = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postSlackPayload([
            'type' => 'block_actions',
            'user' => ['id' => 'U_NOT_APPROVER'],
            'actions' => [
                [
                    'action_id' => SlackApprovalService::ACTION_APPROVE,
                    'value' => (string) $request->id,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('text', 'You are not authorized to approve password resets.');
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_authorized_approver_can_approve_pending_request(): void
    {
        Queue::fake();

        config([
            'slack.signing_secret' => 'test_signing_secret',
            'slack.approver_usergroup_id' => 'S_APPROVERS',
            'slack.bot_token' => 'xoxb-test',
        ]);

        Http::fake([
            'slack.com/api/usergroups.users.list*' => Http::response(['ok' => true, 'users' => ['U_TECH']]),
            'slack.com/api/chat.update' => Http::response(['ok' => true]),
        ]);

        $student = Student::factory()->registered()->create();
        $resetRequest = PasswordResetRequest::factory()->create([
            'student_id' => $student->id,
            'status' => PasswordResetRequestStatus::Pending,
            'expires_at' => now()->addMinutes(10),
            'slack_channel_id' => 'C123',
            'slack_message_ts' => '1234.5678',
        ]);

        app(PendingPasswordService::class)->store($resetRequest, 'Mint-River-4321-Sky', PendingPasswordType::TemporaryGenerated);

        $this->postSlackPayload([
            'type' => 'block_actions',
            'user' => ['id' => 'U_TECH'],
            'actions' => [
                [
                    'action_id' => SlackApprovalService::ACTION_APPROVE,
                    'value' => (string) $resetRequest->id,
                ],
            ],
        ])->assertOk();

        $resetRequest->refresh();
        $this->assertSame(PasswordResetRequestStatus::ApprovedProcessing, $resetRequest->status);
        $this->assertSame('U_TECH', $resetRequest->approved_by_slack_user_id);
        Queue::assertPushed(ResetDirectoryPasswordsJob::class);
    }

    public function test_cannot_approve_twice(): void
    {
        Queue::fake();

        config([
            'slack.signing_secret' => 'test_signing_secret',
            'slack.approver_usergroup_id' => 'S_APPROVERS',
        ]);

        Http::fake([
            'slack.com/api/usergroups.users.list*' => Http::response(['ok' => true, 'users' => ['U_TECH']]),
            'slack.com/api/chat.update' => Http::response(['ok' => true]),
        ]);

        $resetRequest = PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::ApprovedProcessing,
            'approved_by_slack_user_id' => 'U_TECH',
            'approved_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postSlackPayload([
            'type' => 'block_actions',
            'user' => ['id' => 'U_TECH'],
            'actions' => [
                [
                    'action_id' => SlackApprovalService::ACTION_APPROVE,
                    'value' => (string) $resetRequest->id,
                ],
            ],
        ]);

        $response->assertJsonPath('text', 'This request was already approved_processing.');
        Queue::assertNothingPushed();
    }
}

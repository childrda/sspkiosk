<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackApiClient
{
    private function client(): PendingRequest
    {
        return Http::baseUrl('https://slack.com/api/')
            ->withToken((string) config('slack.bot_token'))
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postMessage(array $payload): array
    {
        $response = $this->client()->post('chat.postMessage', $payload);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateMessage(array $payload): array
    {
        $response = $this->client()->post('chat.update', $payload);

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function listUsergroupMembers(string $usergroupId): array
    {
        $response = $this->client()->get('usergroups.users.list', [
            'usergroup' => $usergroupId,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Upload a file to a channel using files.getUploadURLExternal + files.completeUploadExternal.
     *
     * @return array<string, mixed>
     */
    public function uploadFile(
        string $channelId,
        string $filename,
        string $contents,
        string $title,
        ?string $threadTs = null,
    ): array {
        $token = (string) config('slack.bot_token');
        $length = strlen($contents);

        $uploadUrlResponse = Http::withToken($token)
            ->acceptJson()
            ->asForm()
            ->post('https://slack.com/api/files.getUploadURLExternal', [
                'filename' => $filename,
                'length' => $length,
            ])
            ->json();

        if (! ($uploadUrlResponse['ok'] ?? false)) {
            Log::warning('Slack files.getUploadURLExternal failed.', [
                'error' => $uploadUrlResponse['error'] ?? 'unknown',
            ]);

            return $uploadUrlResponse ?? ['ok' => false];
        }

        $uploadUrl = (string) ($uploadUrlResponse['upload_url'] ?? '');
        $fileId = (string) ($uploadUrlResponse['file_id'] ?? '');

        if ($uploadUrl === '' || $fileId === '') {
            return ['ok' => false, 'error' => 'missing_upload_url_or_file_id'];
        }

        $binaryUpload = Http::withBody($contents, 'application/octet-stream')
            ->post($uploadUrl);

        if (! $binaryUpload->successful()) {
            Log::warning('Slack binary file upload failed.', [
                'status' => $binaryUpload->status(),
            ]);

            return ['ok' => false, 'error' => 'binary_upload_failed'];
        }

        $completePayload = [
            'files' => [
                [
                    'id' => $fileId,
                    'title' => $title,
                ],
            ],
            'channel_id' => $channelId,
        ];

        if ($threadTs !== null) {
            $completePayload['thread_ts'] = $threadTs;
        }

        $completeResponse = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post('https://slack.com/api/files.completeUploadExternal', $completePayload)
            ->json();

        if (! ($completeResponse['ok'] ?? false)) {
            Log::warning('Slack files.completeUploadExternal failed.', [
                'error' => $completeResponse['error'] ?? 'unknown',
            ]);
        }

        return $completeResponse ?? ['ok' => false];
    }
}

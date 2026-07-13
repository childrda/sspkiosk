<?php

namespace Tests\Support;

use App\Models\Kiosk;
use App\Services\KioskSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait SignsKioskRequests
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function kioskAuthHeaders(
        Kiosk $kiosk,
        string $secret,
        string $method,
        string $path,
        array $data = [],
        string $content = '',
    ): array {
        $nonce = (string) Str::uuid();
        $timestamp = (string) now()->timestamp;
        $path = '/'.ltrim($path, '/');

        $request = Request::create($path, $method, $data, [], [], [], $content);
        $request->headers->set(KioskSecurityService::HEADER_KIOSK_ID, $kiosk->kiosk_uuid);
        $request->headers->set(KioskSecurityService::HEADER_TIMESTAMP, $timestamp);
        $request->headers->set(KioskSecurityService::HEADER_NONCE, $nonce);

        $security = app(KioskSecurityService::class);
        $signature = $security->signPayload($security->buildCanonicalPayload($request), $secret);

        return [
            KioskSecurityService::HEADER_KIOSK_ID => $kiosk->kiosk_uuid,
            KioskSecurityService::HEADER_TIMESTAMP => $timestamp,
            KioskSecurityService::HEADER_NONCE => $nonce,
            KioskSecurityService::HEADER_SIGNATURE => $signature,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function kioskHeartbeatHeaders(Kiosk $kiosk, string $secret, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);
    }

    protected function postSignedKioskRequest(array $headers, string $body): \Illuminate\Testing\TestResponse
    {
        $server = $this->transformHeadersToServerVars(array_merge($headers, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]));

        return $this->call('POST', route('kiosk.heartbeat'), [], [], [], $server, $body);
    }
}

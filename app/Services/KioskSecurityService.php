<?php

namespace App\Services;

use App\Enums\KioskStatus;
use App\Exceptions\KioskAuthenticationException;
use App\Models\Kiosk;
use App\Models\UsedNonce;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;

class KioskSecurityService
{
    public const HEADER_KIOSK_ID = 'X-Kiosk-Id';

    public const HEADER_TIMESTAMP = 'X-Kiosk-Timestamp';

    public const HEADER_NONCE = 'X-Kiosk-Nonce';

    public const HEADER_SIGNATURE = 'X-Kiosk-Signature';

    public function __construct(
        private readonly KioskCredentialService $credentials,
        private readonly KioskNetworkService $networks,
    ) {}

    public function verifyRequest(Request $request, bool $requireFreshHeartbeat = false): Kiosk
    {
        $kiosk = $this->resolveKiosk($request);
        $this->assertKioskIsOperational($kiosk, $requireFreshHeartbeat);
        $this->assertIpAllowed($request, $kiosk);
        $this->assertValidSignature($request, $kiosk);
        $this->recordNonce($kiosk, (string) $request->header(self::HEADER_NONCE));

        $request->attributes->set('kiosk', $kiosk);

        return $kiosk;
    }

    public function recordHeartbeat(Kiosk $kiosk, Request $request): void
    {
        $kiosk->forceFill([
            'last_seen_at' => now(),
        ])->save();

        app(AuditLogService::class)->logKiosk(
            'kiosk.heartbeat',
            $kiosk->id,
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_fingerprint' => $request->input('device_fingerprint'),
            ],
            $request,
        );
    }

    public function hasFreshHeartbeat(Kiosk $kiosk): bool
    {
        if (! config('kiosk.require_active_heartbeat')) {
            return true;
        }

        if ($kiosk->last_seen_at === null) {
            return false;
        }

        $expiresAfter = config('kiosk.heartbeat_expires_after_seconds');

        return $kiosk->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($expiresAfter));
    }

    public function buildCanonicalPayload(Request $request): string
    {
        $kioskId = (string) $request->header(self::HEADER_KIOSK_ID);
        $timestamp = (string) $request->header(self::HEADER_TIMESTAMP);
        $nonce = (string) $request->header(self::HEADER_NONCE);
        $bodyHash = hash('sha256', $request->getContent());

        return implode("\n", [
            $kioskId,
            $timestamp,
            $nonce,
            strtoupper($request->method()),
            '/'.ltrim($request->path(), '/'),
            $bodyHash,
        ]);
    }

    public function signPayload(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private function resolveKiosk(Request $request): Kiosk
    {
        $kioskUuid = trim((string) $request->header(self::HEADER_KIOSK_ID));

        if ($kioskUuid === '') {
            throw new KioskAuthenticationException('Missing kiosk identifier.', 'missing_kiosk_id');
        }

        $kiosk = Kiosk::query()->where('kiosk_uuid', $kioskUuid)->first();

        if (! $kiosk) {
            throw new KioskAuthenticationException('Unknown kiosk.', 'unknown_kiosk');
        }

        return $kiosk;
    }

    private function assertKioskIsOperational(Kiosk $kiosk, bool $requireFreshHeartbeat): void
    {
        if ($kiosk->status !== KioskStatus::Active) {
            throw new KioskAuthenticationException('Kiosk is disabled.', 'kiosk_disabled');
        }

        if (! $this->credentials->hasDeviceAgentCredential($kiosk)) {
            throw new KioskAuthenticationException('Kiosk is not enrolled.', 'kiosk_not_enrolled');
        }

        if ($requireFreshHeartbeat && ! $this->hasFreshHeartbeat($kiosk)) {
            throw new KioskAuthenticationException('Kiosk heartbeat is stale.', 'heartbeat_stale');
        }
    }

    private function assertIpAllowed(Request $request, Kiosk $kiosk): void
    {
        if (! $this->networks->isRequestIpAllowed($request, $kiosk)) {
            throw new KioskAuthenticationException('Request IP is not allowed.', 'ip_not_allowed');
        }
    }

    private function assertValidSignature(Request $request, Kiosk $kiosk): void
    {
        $timestamp = (string) $request->header(self::HEADER_TIMESTAMP);
        $nonce = (string) $request->header(self::HEADER_NONCE);
        $signature = (string) $request->header(self::HEADER_SIGNATURE);

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new KioskAuthenticationException('Missing kiosk signature headers.', 'missing_signature_headers');
        }

        if (! ctype_digit($timestamp)) {
            throw new KioskAuthenticationException('Invalid kiosk timestamp.', 'invalid_timestamp');
        }

        $timestampInt = (int) $timestamp;
        $tolerance = config('kiosk.hmac_tolerance_seconds');
        $now = now()->timestamp;

        if (abs($now - $timestampInt) > $tolerance) {
            throw new KioskAuthenticationException('Kiosk timestamp is outside tolerance.', 'timestamp_expired');
        }

        if ($this->nonceWasUsed($kiosk, $nonce)) {
            throw new KioskAuthenticationException('Nonce has already been used.', 'nonce_reused');
        }

        $secret = $this->credentials->decryptSecret($kiosk);
        $expected = $this->signPayload($this->buildCanonicalPayload($request), $secret);

        if (! hash_equals($expected, $signature)) {
            throw new KioskAuthenticationException('Invalid kiosk signature.', 'invalid_signature');
        }
    }

    private function nonceWasUsed(Kiosk $kiosk, string $nonce): bool
    {
        return UsedNonce::query()
            ->where('kiosk_id', $kiosk->id)
            ->where('nonce', $nonce)
            ->exists();
    }

    private function recordNonce(Kiosk $kiosk, string $nonce): void
    {
        try {
            UsedNonce::query()->create([
                'kiosk_id' => $kiosk->id,
                'nonce' => $nonce,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new KioskAuthenticationException('Nonce has already been used.', 'nonce_reused');
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\KioskHeartbeatRequest;
use App\Models\Kiosk;
use App\Services\KioskSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskHeartbeatController extends Controller
{
    public function __construct(
        private readonly KioskSecurityService $kioskSecurity,
    ) {}

    public function store(KioskHeartbeatRequest $request): JsonResponse
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');

        $this->kioskSecurity->recordHeartbeat($kiosk, $request);

        return response()->json([
            'status' => 'ok',
            'kiosk_uuid' => $kiosk->kiosk_uuid,
            'last_seen_at' => $kiosk->fresh()->last_seen_at?->toIso8601String(),
            'heartbeat_interval_seconds' => config('kiosk.heartbeat_interval_seconds'),
        ]);
    }

    public function sessionHeartbeat(Request $request): JsonResponse
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');

        $kiosk->forceFill([
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'status' => 'ok',
            'heartbeat_interval_seconds' => config('kiosk.heartbeat_interval_seconds'),
        ]);
    }
}

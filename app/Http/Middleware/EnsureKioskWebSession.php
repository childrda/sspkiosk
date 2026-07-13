<?php

namespace App\Http\Middleware;

use App\Models\Kiosk;
use App\Services\AuditLogService;
use App\Services\KioskNetworkService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKioskWebSession
{
    public function __construct(
        private readonly KioskNetworkService $networks,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sessionKey = config('kiosk.registration_session_kiosk_key');
        $ip = $request->ip();

        if ($ip === null) {
            return redirect()
                ->route('kiosk.reset.unavailable')
                ->with('error', 'This kiosk is not set up. Please ask technology staff for help.');
        }

        $resolvedKiosk = $this->networks->findEnrolledKioskByIp($ip);
        $sessionKioskId = $request->session()->get($sessionKey);

        if ($resolvedKiosk !== null && $sessionKioskId !== null && (int) $sessionKioskId !== $resolvedKiosk->id) {
            $this->auditLogs->logKiosk(
                'kiosk.session.ip_mismatch',
                $resolvedKiosk->id,
                [
                    'session_kiosk_id' => (int) $sessionKioskId,
                    'resolved_kiosk_id' => $resolvedKiosk->id,
                    'source_ip' => $ip,
                ],
                $request,
            );

            $request->session()->put($sessionKey, $resolvedKiosk->id);
            $sessionKioskId = $resolvedKiosk->id;
        } elseif ($resolvedKiosk !== null && $sessionKioskId === null) {
            $request->session()->put($sessionKey, $resolvedKiosk->id);

            $this->auditLogs->logKiosk(
                'kiosk.session.ip_resolved',
                $resolvedKiosk->id,
                [
                    'source_ip' => $ip,
                    'reason' => 'missing_web_session',
                ],
                $request,
            );

            $sessionKioskId = $resolvedKiosk->id;
        } elseif ($resolvedKiosk === null && $sessionKioskId !== null) {
            return redirect()
                ->route('kiosk.reset.unavailable')
                ->with('error', 'This kiosk is not set up. Please ask technology staff for help.');
        } elseif ($resolvedKiosk === null && $sessionKioskId === null) {
            return redirect()
                ->route('kiosk.reset.unavailable')
                ->with('error', 'This kiosk is not set up. Please ask technology staff for help.');
        }

        $kiosk = $resolvedKiosk ?? Kiosk::query()->find($sessionKioskId);

        if (! $kiosk || ! $kiosk->isActive()) {
            return redirect()
                ->route('kiosk.reset.unavailable')
                ->with('error', 'This kiosk is not available.');
        }

        if (! $this->networks->isRequestIpAllowed($request, $kiosk)) {
            abort(403, 'Request IP is not allowed.');
        }

        $request->attributes->set('kiosk', $kiosk);

        return $next($request);
    }
}

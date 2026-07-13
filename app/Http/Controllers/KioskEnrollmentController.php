<?php

namespace App\Http\Controllers;

use App\Exceptions\KioskAuthenticationException;
use App\Http\Requests\KioskEnrollRequest;
use App\Services\AuditLogService;
use App\Services\KioskEnrollmentService;
use App\Services\KioskNetworkService;
use App\Services\KioskProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class KioskEnrollmentController extends Controller
{
    public function __construct(
        private readonly KioskEnrollmentService $enrollment,
        private readonly KioskNetworkService $networks,
        private readonly AuditLogService $auditLog,
    ) {}

    public function showEnroll(): View
    {
        return view('kiosk.enroll');
    }

    public function enroll(KioskEnrollRequest $request): JsonResponse|RedirectResponse
    {
        if (! $this->networks->isRequestIpAllowed($request)) {
            return $this->enrollmentFailureResponse(
                $request,
                'Request IP is not allowed.',
                'ip_not_allowed',
                403,
            );
        }

        $deviceAgent = $request->expectsJson();

        try {
            $result = $this->enrollment->enroll(
                $request->validated('enrollment_code'),
                (string) $request->ip(),
                $deviceAgent,
            );
        } catch (KioskAuthenticationException $exception) {
            $this->auditLog->logSystem('kiosk.enrollment.failed', 'kiosk', null, [
                'reason' => $exception->getReasonCode(),
                'ip_address' => $request->ip(),
            ]);

            return $this->enrollmentFailureResponse(
                $request,
                $exception->getMessage(),
                $exception->getReasonCode(),
                $exception->getCode(),
            );
        }

        $this->auditLog->logSystem('kiosk.enrollment.completed', 'kiosk', (string) $result['kiosk_id'], [
            'kiosk_uuid' => $result['kiosk_uuid'],
            'enrollment_type' => $result['enrollment_type'],
            'ip_address' => $request->ip(),
        ]);

        if ($deviceAgent) {
            return response()->json([
                'kiosk_id' => $result['kiosk_id'],
                'kiosk_uuid' => $result['kiosk_uuid'],
                'secret' => $result['secret'],
                'message' => 'Store the secret on the kiosk device. It will not be shown again.',
            ]);
        }

        $request->session()->put(
            config('kiosk.registration_session_kiosk_key'),
            $result['kiosk_id'],
        );

        return redirect()
            ->route('kiosk.enroll.complete')
            ->with('browser_enrollment', true)
            ->with('enrolled_kiosk_name', $result['kiosk_name'])
            ->with('enrolled_kiosk_allowed_ip', $result['allowed_ip']);
    }

    public function showEnrollComplete(): View|RedirectResponse
    {
        if (session('browser_enrollment')) {
            return view('kiosk.enroll-browser-complete', [
                'kioskName' => session('enrolled_kiosk_name'),
                'allowedIp' => session('enrolled_kiosk_allowed_ip'),
            ]);
        }

        if (! session()->has('kiosk_secret')) {
            return redirect()
                ->route('kiosk.enroll.form')
                ->with('error', 'No enrollment secret is available. Complete enrollment first.');
        }

        return view('kiosk.enroll-complete', [
            'kioskSecret' => session('kiosk_secret'),
            'kioskUuid' => session('enrolled_kiosk_uuid'),
            'agentConfig' => app(KioskProvisioningService::class)->buildAgentConfigIni(
                (string) session('enrolled_kiosk_uuid'),
                (string) session('kiosk_secret'),
            ),
        ]);
    }

    private function enrollmentFailureResponse(
        KioskEnrollRequest $request,
        string $message,
        string $reason,
        int $status,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'reason' => $reason,
            ], $status);
        }

        return redirect()
            ->route('kiosk.enroll.form')
            ->withInput()
            ->with('error', $message);
    }
}

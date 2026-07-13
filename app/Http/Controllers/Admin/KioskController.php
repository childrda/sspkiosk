<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKioskRequest;
use App\Models\Kiosk;
use App\Services\AdminKioskService;
use App\Services\AuditLogService;
use App\Services\KioskProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class KioskController extends Controller
{
    public function __construct(
        private readonly AdminKioskService $adminKiosks,
        private readonly KioskProvisioningService $provisioning,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request): View
    {
        $archived = $request->boolean('archived');

        $query = $archived ? Kiosk::onlyTrashed() : Kiosk::query();

        $kiosks = $query
            ->withCount('passwordResetRequests')
            ->orderBy('name')
            ->get()
            ->map(function (Kiosk $kiosk): Kiosk {
                $kiosk->setAttribute('is_online', $this->adminKiosks->isOnline($kiosk));

                return $kiosk;
            });

        return view('admin.kiosks.index', [
            'kiosks' => $kiosks,
            'archived' => $archived,
        ]);
    }

    public function show(Kiosk $kiosk): View
    {
        $kiosk->loadCount('passwordResetRequests');

        $recentRequests = $kiosk->passwordResetRequests()
            ->with('student')
            ->latest('requested_at')
            ->limit(20)
            ->get();

        return view('admin.kiosks.show', [
            'kiosk' => $kiosk,
            'isOnline' => $this->adminKiosks->isOnline($kiosk),
            'isEnrolled' => $kiosk->secret_hash !== null,
            'recentRequests' => $recentRequests,
        ]);
    }

    public function store(StoreKioskRequest $request): RedirectResponse
    {
        $result = $this->adminKiosks->createKiosk(
            $request->validated(),
            (int) $request->user()->id,
        );

        return redirect()
            ->route('admin.kiosks.show', $result['kiosk'])
            ->with('status', 'Kiosk created.')
            ->with('enrollment_code', $result['enrollment_code']);
    }

    public function disable(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $this->adminKiosks->disable($kiosk, (int) $request->user()->id);

        return redirect()
            ->route('admin.kiosks.show', $kiosk)
            ->with('status', 'Kiosk disabled.');
    }

    public function enable(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $this->adminKiosks->enable($kiosk, (int) $request->user()->id);

        return redirect()
            ->route('admin.kiosks.show', $kiosk)
            ->with('status', 'Kiosk enabled.');
    }

    public function rotateSecret(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $secret = $this->adminKiosks->rotateSecret($kiosk, (int) $request->user()->id);

        return redirect()
            ->route('admin.kiosks.show', $kiosk)
            ->with('status', 'Kiosk secret rotated. Copy it now — it will not be shown again.')
            ->with('kiosk_secret', $secret)
            ->with('kiosk_secret_for', $kiosk->id);
    }

    public function provisioningBundle(Request $request, Kiosk $kiosk): Response|RedirectResponse
    {
        $secret = $request->session()->get('kiosk_secret');
        $secretFor = $request->session()->get('kiosk_secret_for');

        if ($secret === null || (int) $secretFor !== $kiosk->id) {
            abort(404);
        }

        $this->auditLog->logAdmin(
            'kiosk.provisioning_bundle.downloaded',
            (int) $request->user()->id,
            'kiosk',
            (string) $kiosk->id,
            ['kiosk_uuid' => $kiosk->kiosk_uuid],
            $request,
        );

        $ini = $this->provisioning->buildAgentConfigIni($kiosk->kiosk_uuid, $secret);

        $request->session()->forget(['kiosk_secret', 'kiosk_secret_for']);

        return response($ini, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="agent.conf"',
        ]);
    }

    public function issueEnrollmentCode(Request $request, Kiosk $kiosk): RedirectResponse
    {
        try {
            $code = $this->adminKiosks->issueEnrollmentCode($kiosk, (int) $request->user()->id);
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('admin.kiosks.show', $kiosk)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.kiosks.show', $kiosk)
            ->with('status', 'Enrollment code issued.')
            ->with('enrollment_code', $code);
    }

    public function destroy(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $this->adminKiosks->archive($kiosk, (int) $request->user()->id);

        return redirect()
            ->route('admin.kiosks.index')
            ->with('status', 'Kiosk archived. Reset request history is retained.');
    }

    public function restore(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $this->adminKiosks->restore($kiosk, (int) $request->user()->id);

        return redirect()
            ->route('admin.kiosks.show', $kiosk)
            ->with('status', 'Kiosk restored. Enable and re-enroll it before putting it back in service.');
    }
}

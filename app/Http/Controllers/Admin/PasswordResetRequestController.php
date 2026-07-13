<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\StudentPhotoType;
use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Services\OfficeVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PasswordResetRequestController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $query = PasswordResetRequest::query()
            ->with(['student', 'kiosk'])
            ->latest('requested_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return view('admin.requests.index', [
            'requests' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'statuses' => PasswordResetRequestStatus::cases(),
            'officeVerificationCount' => PasswordResetRequest::query()
                ->where('status', PasswordResetRequestStatus::NeedsOfficeVerification)
                ->count(),
        ]);
    }

    public function show(PasswordResetRequest $passwordResetRequest): View
    {
        $passwordResetRequest->load(['student', 'kiosk', 'resetPhoto', 'officeVerifiedBy']);

        $registrationPhoto = $passwordResetRequest->student
            ->photos()
            ->where('type', StudentPhotoType::Registration)
            ->latest('id')
            ->first();

        return view('admin.requests.show', [
            'resetRequest' => $passwordResetRequest,
            'registrationPhoto' => $registrationPhoto,
        ]);
    }

    public function officeVerify(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        OfficeVerificationService $officeVerification,
    ): RedirectResponse {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $password = $officeVerification->verify(
                $passwordResetRequest,
                $request->user(),
                $validated['notes'] ?? null,
            );
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Identity verified. Give the student the password below once Google confirms the reset.')
            ->with('office_password', $password);
    }

    public function officeReject(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        OfficeVerificationService $officeVerification,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $officeVerification->reject(
                $passwordResetRequest,
                $request->user(),
                $validated['reason'],
            );
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Request rejected.');
    }

    public function retryReset(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        OfficeVerificationService $officeVerification,
    ): RedirectResponse {
        try {
            $password = $officeVerification->retry($passwordResetRequest, $request->user());
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Reset retried. Give the student the password below once Google confirms the reset.')
            ->with('office_password', $password);
    }
}

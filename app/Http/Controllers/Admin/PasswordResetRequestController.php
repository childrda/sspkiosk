<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\StudentPhotoType;
use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Services\OfficeVerificationService;
use App\Services\PasswordRevisionService;
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

    public function show(PasswordResetRequest $passwordResetRequest, PasswordRevisionService $revisions): View
    {
        $passwordResetRequest->load(['student', 'kiosk', 'resetPhoto', 'officeVerifiedBy', 'revisions', 'activeRevision']);

        $registrationPhoto = $passwordResetRequest->student
            ->photos()
            ->where('type', StudentPhotoType::Registration)
            ->latest('id')
            ->first();

        $active = $passwordResetRequest->activeRevision;

        return view('admin.requests.show', [
            'resetRequest' => $passwordResetRequest,
            'registrationPhoto' => $registrationPhoto,
            'activeRevision' => $active,
            'showRetryDirectories' => $active !== null && $revisions->hasRetryableDirectoryFailure($active) && $active->retry_available,
            'showReplacePassword' => $active !== null && $revisions->hasOnlyNoneRetryFailures($active)
                && in_array($passwordResetRequest->status, [
                    PasswordResetRequestStatus::PartiallyCompleted,
                    PasswordResetRequestStatus::Failed,
                ], true),
            'showCancel' => ! in_array($passwordResetRequest->status, [
                PasswordResetRequestStatus::Completed,
                PasswordResetRequestStatus::Denied,
                PasswordResetRequestStatus::Expired,
            ], true),
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
            ->with('status', 'Identity verified. Give the student the password below once directories confirm the reset.')
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
        PasswordRevisionService $revisions,
    ): RedirectResponse {
        $passwordResetRequest->load('activeRevision');

        if ($passwordResetRequest->activeRevision
            && $revisions->hasRetryableDirectoryFailure($passwordResetRequest->activeRevision)
            && $passwordResetRequest->activeRevision->retry_available
        ) {
            try {
                $revisions->retryFailedDirectories($passwordResetRequest, $request->user());
            } catch (ConflictHttpException $exception) {
                return redirect()
                    ->route('admin.requests.show', $passwordResetRequest)
                    ->with('error', $exception->getMessage());
            }

            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('status', 'Retrying failed directories with the existing password.');
        }

        try {
            $password = $officeVerification->retry($passwordResetRequest, $request->user());
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Reset retried with a new office temporary password.')
            ->with('office_password', $password);
    }

    public function startReplacement(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        PasswordRevisionService $revisions,
    ): RedirectResponse {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $revisions->startPasswordReplacement(
                $passwordResetRequest,
                $request->user(),
                $validated['reason'],
                $validated['confirmation'],
            );
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Password replacement started. Have the student return to the kiosk to choose a different password.');
    }

    public function cancel(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        PasswordRevisionService $revisions,
    ): RedirectResponse {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $revisions->cancel(
                $passwordResetRequest,
                $request->user(),
                $validated['reason'],
                $validated['confirmation'],
            );
        } catch (ConflictHttpException $exception) {
            return redirect()
                ->route('admin.requests.show', $passwordResetRequest)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.requests.show', $passwordResetRequest)
            ->with('status', 'Request cancelled. The student must begin a new reset request.');
    }
}

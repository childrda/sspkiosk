<?php

namespace App\Http\Controllers;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\ResetPasswordMode;
use App\Http\Requests\KioskResetLookupRequest;
use App\Http\Requests\KioskResetStudentPasswordRequest;
use App\Http\Requests\KioskResetSubmitRequest;
use App\Http\Requests\StoreRegistrationPhotoRequest;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\AuditLogService;
use App\Services\KioskResetService;
use App\Services\PendingPasswordService;
use App\Services\ResetAttemptLimiterService;
use App\Services\ResetPasswordModeService;
use App\Services\StudentLookupService;
use App\Services\StudentResetPhotoService;
use App\Services\StudentSelectedPasswordValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KioskResetController extends Controller
{
    public function __construct(
        private readonly StudentLookupService $studentLookup,
        private readonly ResetAttemptLimiterService $attemptLimiter,
        private readonly KioskResetService $kioskReset,
        private readonly StudentResetPhotoService $resetPhotos,
        private readonly AuditLogService $auditLog,
        private readonly PendingPasswordService $pendingPasswords,
        private readonly ResetPasswordModeService $resetPasswordMode,
        private readonly StudentSelectedPasswordValidator $studentPasswordValidator,
    ) {}

    public function index(): View
    {
        return view('kiosk.reset.index', [
            'notice' => config('student-password-reset.reset_notice'),
        ]);
    }

    public function unavailable(): View
    {
        return view('kiosk.reset.unavailable');
    }

    public function lookup(KioskResetLookupRequest $request): RedirectResponse
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');
        $identifier = $request->validated('identifier');

        if ($this->attemptLimiter->isKioskLockedOut($kiosk)) {
            return back()->with('error', 'This kiosk cannot accept more reset attempts right now. Please see technology staff.');
        }

        $student = $this->studentLookup->findRegisteredStudent($identifier);

        if ($student === null) {
            $this->auditLog->logKiosk('kiosk.reset.lookup_not_found', $kiosk->id, [
                'identifier' => $identifier,
            ], $request);

            return back()->with('error', config('student-password-reset.reset_lookup_failure_message'));
        }

        if ($this->attemptLimiter->isStudentLockedOut($student)) {
            return back()->with('error', config('student-password-reset.reset_lookup_failure_message'));
        }

        if ($this->kioskReset->hasPendingRequest($student, $kiosk)) {
            return back()->with('error', 'You already have a pending reset request from this kiosk. Please see technology staff.');
        }

        $request->session()->put(config('kiosk.reset_session_student_key'), $student->id);
        $request->session()->forget([
            config('kiosk.reset_session_questions_key'),
            config('kiosk.reset_session_photo_key'),
            config('kiosk.active_reset_request_session_key'),
        ]);

        $this->auditLog->logStudent('kiosk.reset.lookup_success', $student->id, [
            'kiosk_id' => $kiosk->id,
        ], $request);

        return redirect()->route('kiosk.reset.confirm');
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        return view('kiosk.reset.confirm', [
            'student' => $student,
            'displayName' => $this->studentLookup->maskedDisplayName($student),
            'notice' => config('student-password-reset.reset_notice'),
        ]);
    }

    public function showPhoto(Request $request): View|RedirectResponse
    {
        if (! $this->resolveSessionStudent($request)) {
            return redirect()->route('kiosk.reset.index');
        }

        return view('kiosk.reset.photo', [
            'notice' => config('student-password-reset.reset_notice'),
        ]);
    }

    public function storePhoto(StoreRegistrationPhotoRequest $request): RedirectResponse
    {
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        $photo = $this->resetPhotos->storeResetRequestPhoto(
            $student,
            $request->file('photo'),
            $request,
        );

        $presented = $this->kioskReset->presentChallengeQuestions($student);

        $request->session()->put(config('kiosk.reset_session_photo_key'), $photo->id);
        $request->session()->put(config('kiosk.reset_session_questions_key'), $presented);

        return redirect()->route('kiosk.reset.questions');
    }

    public function showQuestions(Request $request): View|RedirectResponse
    {
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        $presented = $request->session()->get(config('kiosk.reset_session_questions_key'), []);

        if ($presented === []) {
            return redirect()->route('kiosk.reset.photo');
        }

        return view('kiosk.reset.questions', [
            'questions' => $presented,
        ]);
    }

    public function submit(KioskResetSubmitRequest $request): RedirectResponse
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        $photoId = $request->session()->get(config('kiosk.reset_session_photo_key'));
        $presented = $request->session()->get(config('kiosk.reset_session_questions_key'), []);
        $photo = $student->photos()->find($photoId);

        if (! $photo || $presented === []) {
            return redirect()->route('kiosk.reset.index')
                ->with('error', 'Your session expired. Please start again.');
        }

        $resetRequest = $this->kioskReset->submitChallengeAnswers(
            $student,
            $kiosk,
            $photo,
            $presented,
            $request->validated('answers'),
            $request,
        );

        if ($resetRequest === null) {
            return redirect()->route('kiosk.reset.password');
        }

        $this->clearResetSession($request);

        if ($resetRequest->status === PasswordResetRequestStatus::Failed) {
            return redirect()
                ->route('kiosk.reset.index')
                ->with('error', config('student-password-reset.reset_challenge_failure_message'));
        }

        $request->session()->put(
            config('kiosk.active_reset_request_session_key'),
            $resetRequest->id,
        );

        return redirect()->route('kiosk.reset.pending-password', $resetRequest);
    }

    public function showPassword(Request $request): View|RedirectResponse
    {
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        if ($this->resetPasswordMode->mode() !== ResetPasswordMode::StudentSelectedPendingApproval) {
            return redirect()->route('kiosk.reset.index');
        }

        return view('kiosk.reset.password', [
            'minLength' => config('student-password-reset.password_policy.min_length'),
        ]);
    }

    public function storePassword(KioskResetStudentPasswordRequest $request): RedirectResponse
    {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');
        $student = $this->resolveSessionStudent($request);

        if (! $student) {
            return redirect()->route('kiosk.reset.index');
        }

        $photoId = $request->session()->get(config('kiosk.reset_session_photo_key'));
        $presented = $request->session()->get(config('kiosk.reset_session_questions_key'), []);
        $photo = $student->photos()->find($photoId);

        if (! $photo || $presented === []) {
            return redirect()->route('kiosk.reset.index')
                ->with('error', 'Your session expired. Please start again.');
        }

        $validator = $this->studentPasswordValidator->validateForStudent(
            $request->input('password'),
            $request->input('password_confirmation'),
            $student,
        );

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $resetRequest = $this->kioskReset->createStudentSelectedRequest(
            $student,
            $kiosk,
            $photo,
            $presented,
            (int) $request->session()->get(config('kiosk.reset_session_challenge_score_key'), count($presented)),
            (string) $request->input('password'),
            $request,
        );

        $this->clearResetSession($request);
        $request->session()->forget(config('kiosk.reset_session_challenge_score_key'));
        $request->session()->put(
            config('kiosk.active_reset_request_session_key'),
            $resetRequest->id,
        );

        return redirect()->route('kiosk.reset.submitted', $resetRequest);
    }

    public function pendingPassword(Request $request, PasswordResetRequest $resetRequest): View|RedirectResponse
    {
        if ($response = $this->authorizeResetRequestAccess($request, $resetRequest)) {
            return $response;
        }

        $this->kioskReset->expireRequestIfNeeded($resetRequest->refresh());

        if ($resetRequest->status !== PasswordResetRequestStatus::Pending) {
            return redirect()->route('kiosk.reset.submitted', $resetRequest);
        }

        if (! $this->pendingPasswords->canDisplayOnce($resetRequest)) {
            return view('kiosk.reset.pending-password-unavailable');
        }

        $password = $this->pendingPasswords->decrypt($resetRequest);

        if ($password === null) {
            return view('kiosk.reset.pending-password-unavailable');
        }

        $this->pendingPasswords->markDisplayed($resetRequest);

        $resetRequest->load('student');

        $this->auditLog->logStudent(
            'kiosk.reset.pending_password.displayed',
            $resetRequest->student_id,
            ['request_id' => $resetRequest->id],
            $request,
        );

        $request->session()->forget(config('kiosk.active_reset_request_session_key'));

        return view('kiosk.reset.pending-password', [
            'resetRequest' => $resetRequest,
            'studentName' => $resetRequest->student->name,
            'temporaryPassword' => $password,
            'displaySeconds' => config('student-password-reset.pending_password.display_seconds'),
            'notice' => config('student-password-reset.pending_temp_password_notice'),
            'copyNoticeEnabled' => config('student-password-reset.pending_password.copy_notice_enabled'),
            'labelPrintingEnabled' => config('student-password-reset.label_printing_enabled'),
            'submittedUrl' => route('kiosk.reset.submitted', $resetRequest),
        ]);
    }

    public function markPrinted(Request $request, PasswordResetRequest $resetRequest): JsonResponse
    {
        if (! config('student-password-reset.label_printing_enabled')) {
            abort(403);
        }

        if ($response = $this->authorizeResetRequestAccess($request, $resetRequest)) {
            abort(403);
        }

        $resetRequest->refresh();

        if ($resetRequest->pending_password_printed_at !== null) {
            abort(409);
        }

        if ($resetRequest->pending_password_displayed_at === null) {
            abort(403);
        }

        $resetRequest->forceFill([
            'pending_password_printed_at' => now(),
        ])->save();

        $this->auditLog->logStudent(
            'password.label_printed',
            $resetRequest->student_id,
            [
                'request_id' => $resetRequest->id,
                'kiosk_id' => $resetRequest->kiosk_id,
            ],
            $request,
        );

        return response()->json(['status' => 'ok']);
    }

    public function submitted(Request $request, PasswordResetRequest $resetRequest): View|RedirectResponse
    {
        if ($response = $this->authorizeResetRequestAccess($request, $resetRequest)) {
            return $response;
        }

        $this->kioskReset->expireRequestIfNeeded($resetRequest->refresh());

        $message = $this->resetPasswordMode->mode() === ResetPasswordMode::StudentSelectedPendingApproval
            ? config('student-password-reset.student_selected_submitted_notice')
            : 'Your request has been submitted. Technology staff will review it. Use the password you wrote down only after it is approved.';

        return view('kiosk.reset.submitted', [
            'message' => $message,
            'resetRequest' => $resetRequest,
        ]);
    }

    private function authorizeResetRequestAccess(
        Request $request,
        PasswordResetRequest $resetRequest,
    ): RedirectResponse|null {
        /** @var Kiosk $kiosk */
        $kiosk = $request->attributes->get('kiosk');

        if ($resetRequest->kiosk_id !== $kiosk->id) {
            abort(403);
        }

        if (! $this->sessionMayAccessResetRequest($request, $resetRequest)) {
            abort(403);
        }

        return null;
    }

    private function sessionMayAccessResetRequest(Request $request, PasswordResetRequest $resetRequest): bool
    {
        if ($resetRequest->kiosk_session_id === null) {
            return false;
        }

        if ($resetRequest->kiosk_session_id === $request->session()->getId()) {
            return true;
        }

        return (int) $request->session()->get(config('kiosk.active_reset_request_session_key')) === $resetRequest->id;
    }

    private function resolveSessionStudent(Request $request): ?Student
    {
        $studentId = $request->session()->get(config('kiosk.reset_session_student_key'));

        return $studentId ? Student::query()->find($studentId) : null;
    }

    private function clearResetSession(Request $request): void
    {
        $request->session()->forget([
            config('kiosk.reset_session_student_key'),
            config('kiosk.reset_session_questions_key'),
            config('kiosk.reset_session_photo_key'),
        ]);
    }
}

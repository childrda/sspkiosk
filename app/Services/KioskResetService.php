<?php

namespace App\Services;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Jobs\SendSlackResetApprovalJob;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Models\StudentPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KioskResetService
{
    public function __construct(
        private readonly ChallengeQuestionService $challengeQuestions,
        private readonly ResetPasswordModeService $resetPasswordMode,
        private readonly PasswordGeneratorService $passwordGenerator,
        private readonly PendingPasswordService $pendingPasswords,
    ) {}

    /**
     * @return array<int, array{id: int, question: string}>
     */
    public function presentChallengeQuestions(Student $student): array
    {
        return $this->challengeQuestions
            ->selectRandomQuestions($student)
            ->map(fn ($question): array => [
                'id' => $question->id,
                'question' => $question->question_text,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id: int, question: string}>  $presentedQuestions
     * @param  array<int|string, string>  $submittedAnswers
     */
    /**
     * Returns null when student-selected mode passed challenges and password entry is next.
     */
    public function submitChallengeAnswers(
        Student $student,
        Kiosk $kiosk,
        StudentPhoto $resetPhoto,
        array $presentedQuestions,
        array $submittedAnswers,
        Request $request,
    ): ?PasswordResetRequest {
        $questionIds = collect($presentedQuestions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $answersById = [];

        foreach ($submittedAnswers as $key => $value) {
            $answersById[(int) $key] = (string) $value;
        }

        $score = $this->challengeQuestions->validateAnswers($student, $questionIds, $answersById);
        $required = config('student-password-reset.challenge_questions_required_correct');

        return DB::transaction(function () use (
            $student,
            $kiosk,
            $resetPhoto,
            $presentedQuestions,
            $score,
            $required,
            $request,
        ): ?PasswordResetRequest {
            if ($score < $required) {
                $failed = $this->createRequest(
                    $student,
                    $kiosk,
                    $resetPhoto,
                    $presentedQuestions,
                    $score,
                    PasswordResetRequestStatus::Failed,
                    $request,
                );

                app(AuditLogService::class)->logStudent(
                    'student.reset.challenge_failed',
                    $student->id,
                    [
                        'request_id' => $failed->id,
                        'challenge_score' => $score,
                        'required' => $required,
                    ],
                    $request,
                );

                return $failed;
            }

            if ($this->resetPasswordMode->mode() === ResetPasswordMode::StudentSelectedPendingApproval) {
                $request->session()->put(config('kiosk.reset_session_challenge_score_key'), $score);

                app(AuditLogService::class)->logStudent(
                    'student.reset.challenge_passed',
                    $student->id,
                    ['awaiting_password' => true, 'challenge_score' => $score],
                    $request,
                );

                return null;
            }

            return $this->finalizeTemporaryGeneratedRequest(
                $student,
                $kiosk,
                $resetPhoto,
                $presentedQuestions,
                $score,
                $request,
            );
        });
    }

    public function createStudentSelectedRequest(
        Student $student,
        Kiosk $kiosk,
        StudentPhoto $resetPhoto,
        array $presentedQuestions,
        int $score,
        string $plainPassword,
        Request $httpRequest,
    ): PasswordResetRequest {
        $pending = $this->createRequest(
            $student,
            $kiosk,
            $resetPhoto,
            $presentedQuestions,
            $score,
            PasswordResetRequestStatus::Pending,
            $httpRequest,
            withoutPendingPassword: true,
        );

        $this->pendingPasswords->store($pending, $plainPassword, PendingPasswordType::StudentSelected);

        app(AuditLogService::class)->logStudent(
            'student.reset.requested',
            $student->id,
            [
                'request_id' => $pending->id,
                'reset_mode' => $pending->reset_mode,
            ],
            $httpRequest,
        );

        SendSlackResetApprovalJob::dispatch($pending->id);

        return $pending->refresh();
    }

    public function hasEncryptedPendingPassword(PasswordResetRequest $request): bool
    {
        return $this->pendingPasswords->hasEncryptedPendingPassword($request);
    }

    public function hasPendingRequest(Student $student, Kiosk $kiosk): bool
    {
        return PasswordResetRequest::query()
            ->where('student_id', $student->id)
            ->where('kiosk_id', $kiosk->id)
            ->where('status', PasswordResetRequestStatus::Pending)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function expireRequestIfNeeded(PasswordResetRequest $request): void
    {
        if ($request->status !== PasswordResetRequestStatus::Pending) {
            return;
        }

        if (! $request->expires_at->isPast()) {
            return;
        }

        $request->update(['status' => PasswordResetRequestStatus::Expired]);
        $this->pendingPasswords->delete($request, 'expiration');

        app(AuditLogService::class)->logSystem(
            'reset.request.expired',
            'password_reset_request',
            (string) $request->id,
            ['student_id' => $request->student_id],
        );
    }

    /**
     * @param  array<int, array{id: int, question: string}>  $presentedQuestions
     */
    private function finalizeTemporaryGeneratedRequest(
        Student $student,
        Kiosk $kiosk,
        StudentPhoto $resetPhoto,
        array $presentedQuestions,
        int $score,
        Request $request,
    ): PasswordResetRequest {
        $pending = $this->createRequest(
            $student,
            $kiosk,
            $resetPhoto,
            $presentedQuestions,
            $score,
            PasswordResetRequestStatus::Pending,
            $request,
        );

        $plainPassword = $this->passwordGenerator->generate();
        $this->pendingPasswords->store($pending, $plainPassword, PendingPasswordType::TemporaryGenerated);

        app(AuditLogService::class)->logStudent(
            'student.reset.requested',
            $student->id,
            [
                'request_id' => $pending->id,
                'reset_mode' => $pending->reset_mode,
            ],
            $request,
        );

        SendSlackResetApprovalJob::dispatch($pending->id);

        return $pending->refresh();
    }

    /**
     * @param  array<int, array{id: int, question: string}>  $presentedQuestions
     */
    private function createRequest(
        Student $student,
        Kiosk $kiosk,
        StudentPhoto $resetPhoto,
        array $presentedQuestions,
        int $score,
        PasswordResetRequestStatus $status,
        Request $request,
        bool $withoutPendingPassword = false,
    ): PasswordResetRequest {
        $expirationMinutes = config('student-password-reset.reset_request_expiration_minutes');
        $mode = $this->resetPasswordMode->mode();

        return PasswordResetRequest::query()->create([
            'student_id' => $student->id,
            'kiosk_id' => $kiosk->id,
            'kiosk_session_id' => $request->session()->getId(),
            'status' => $status,
            'reset_mode' => $mode->value,
            'challenge_questions_presented' => $presentedQuestions,
            'challenge_score' => $score,
            'reset_photo_id' => $resetPhoto->id,
            'requested_at' => now(),
            'expires_at' => now()->addMinutes($expirationMinutes),
            'pending_password_expires_at' => $withoutPendingPassword
                ? null
                : now()->addMinutes($expirationMinutes),
        ]);
    }
}

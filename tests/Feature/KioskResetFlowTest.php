<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Jobs\SendSlackResetApprovalJob;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\ChallengeQuestionService;
use App\Services\KioskCredentialService;
use App\Services\KioskEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class KioskResetFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    private function bindKioskSession(array $headers, int $kioskId): array
    {
        return array_merge($headers, [
            config('kiosk.registration_session_kiosk_key') => $kioskId,
        ]);
    }

    private function enrolledKioskWithSession(): array
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();
        $kiosk = \App\Models\Kiosk::factory()->enrolled()->create([
            'secret_hash' => $credentials->encryptSecret($secret),
            'allowed_ip' => '127.0.0.1',
            'last_seen_at' => now(),
        ]);

        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/bind-session');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders($headers)
            ->post(route('kiosk.bind-session'));

        return [$kiosk, $secret, $this->bindKioskSession([], $kiosk->id)];
    }

    private function kioskRequest()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function registeredStudentWithQuestions(): Student
    {
        $student = Student::factory()->registered()->create([
            'email' => 'alex@students.example.org',
            'name' => 'Alex Johnson',
        ]);

        app(ChallengeQuestionService::class)->storeAnswers($student, [
            ['question' => 'Favorite color?', 'answer' => 'Blue'],
            ['question' => 'First pet name?', 'answer' => 'Rex'],
            ['question' => 'Street name?', 'answer' => 'Oak'],
        ]);

        return $student;
    }

    public function test_lookup_shows_generic_failure_for_unknown_student(): void
    {
        [, $secret, $session] = $this->enrolledKioskWithSession();
        $kiosk = \App\Models\Kiosk::query()->first();
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');

        $response = $this->kioskRequest()->withSession($session)
            ->withHeaders($headers)
            ->post(route('kiosk.reset.lookup'), ['identifier' => 'unknown@students.example.org']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_successful_reset_request_flow(): void
    {
        Queue::fake();
        Storage::fake('local');

        [, $secret, $session] = $this->enrolledKioskWithSession();
        $kiosk = \App\Models\Kiosk::query()->first();
        $student = $this->registeredStudentWithQuestions();

        $lookupHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($lookupHeaders)
            ->post(route('kiosk.reset.lookup'), ['identifier' => $student->email])
            ->assertRedirect(route('kiosk.reset.confirm'));

        $session = array_merge($session, [
            config('kiosk.reset_session_student_key') => $student->id,
        ]);

        $photoHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/photo');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($photoHeaders)
            ->post(route('kiosk.reset.photo.store'), [
                'photo' => UploadedFile::fake()->create('reset.jpg', 100, 'image/jpeg'),
            ])
            ->assertRedirect(route('kiosk.reset.questions'));

        $presented = session(config('kiosk.reset_session_questions_key'));
        $answers = [];
        foreach ($presented as $question) {
            $answers[$question['id']] = match ($question['question']) {
                'Favorite color?' => 'Blue',
                'First pet name?' => 'Rex',
                default => 'Oak',
            };
        }

        $session = array_merge($session, [
            config('kiosk.reset_session_photo_key') => session(config('kiosk.reset_session_photo_key')),
            config('kiosk.reset_session_questions_key') => $presented,
        ]);

        $submitHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/submit');
        $response = $this->kioskRequest()->withSession($session)
            ->withHeaders($submitHeaders)
            ->post(route('kiosk.reset.submit'), ['answers' => $answers]);

        $response->assertRedirect();

        $request = PasswordResetRequest::query()->first();
        $this->assertSame(PasswordResetRequestStatus::Pending, $request->status);
        $this->assertSame(3, $request->challenge_score);
        Queue::assertPushed(SendSlackResetApprovalJob::class);
    }

    public function test_failed_challenge_does_not_queue_slack_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        [, $secret, $session] = $this->enrolledKioskWithSession();
        $kiosk = \App\Models\Kiosk::query()->first();
        $student = $this->registeredStudentWithQuestions();

        $lookupHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($lookupHeaders)
            ->post(route('kiosk.reset.lookup'), ['identifier' => $student->email]);

        $session[config('kiosk.reset_session_student_key')] = $student->id;

        $photoHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/photo');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($photoHeaders)
            ->post(route('kiosk.reset.photo.store'), [
                'photo' => UploadedFile::fake()->create('reset.jpg', 100, 'image/jpeg'),
            ]);

        $presented = session(config('kiosk.reset_session_questions_key'));
        $answers = [];
        foreach ($presented as $question) {
            $answers[$question['id']] = 'wrong-answer';
        }

        $session[config('kiosk.reset_session_photo_key')] = session(config('kiosk.reset_session_photo_key'));
        $session[config('kiosk.reset_session_questions_key')] = $presented;

        $submitHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/submit');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($submitHeaders)
            ->post(route('kiosk.reset.submit'), ['answers' => $answers]);

        $this->assertSame(PasswordResetRequestStatus::Failed, PasswordResetRequest::query()->first()->status);
        Queue::assertNothingPushed();
    }

    public function test_student_selected_passing_challenge_redirects_to_password_without_creating_request(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['student-password-reset.reset_password_mode' => 'student_selected_pending_approval']);

        [, $secret, $session] = $this->enrolledKioskWithSession();
        $kiosk = \App\Models\Kiosk::query()->first();
        $student = $this->registeredStudentWithQuestions();

        $lookupHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($lookupHeaders)
            ->post(route('kiosk.reset.lookup'), ['identifier' => $student->email])
            ->assertRedirect(route('kiosk.reset.confirm'));

        $session = array_merge($session, [
            config('kiosk.reset_session_student_key') => $student->id,
        ]);

        $photoHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/photo');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($photoHeaders)
            ->post(route('kiosk.reset.photo.store'), [
                'photo' => UploadedFile::fake()->create('reset.jpg', 100, 'image/jpeg'),
            ])
            ->assertRedirect(route('kiosk.reset.questions'));

        $presented = session(config('kiosk.reset_session_questions_key'));
        $answers = [];
        foreach ($presented as $question) {
            $answers[$question['id']] = match ($question['question']) {
                'Favorite color?' => 'Blue',
                'First pet name?' => 'Rex',
                default => 'Oak',
            };
        }

        $session = array_merge($session, [
            config('kiosk.reset_session_photo_key') => session(config('kiosk.reset_session_photo_key')),
            config('kiosk.reset_session_questions_key') => $presented,
        ]);

        $submitHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/submit');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($submitHeaders)
            ->post(route('kiosk.reset.submit'), ['answers' => $answers])
            ->assertRedirect(route('kiosk.reset.password'));

        $this->assertSame(0, PasswordResetRequest::query()->count());
        $this->assertSame(3, session(config('kiosk.reset_session_challenge_score_key')));
        Queue::assertNothingPushed();
    }

    public function test_student_selected_failed_challenge_still_creates_failed_request(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['student-password-reset.reset_password_mode' => 'student_selected_pending_approval']);

        [, $secret, $session] = $this->enrolledKioskWithSession();
        $kiosk = \App\Models\Kiosk::query()->first();
        $student = $this->registeredStudentWithQuestions();

        $lookupHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/lookup');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($lookupHeaders)
            ->post(route('kiosk.reset.lookup'), ['identifier' => $student->email]);

        $session[config('kiosk.reset_session_student_key')] = $student->id;

        $photoHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/photo');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($photoHeaders)
            ->post(route('kiosk.reset.photo.store'), [
                'photo' => UploadedFile::fake()->create('reset.jpg', 100, 'image/jpeg'),
            ]);

        $presented = session(config('kiosk.reset_session_questions_key'));
        $answers = [];
        foreach ($presented as $question) {
            $answers[$question['id']] = 'wrong-answer';
        }

        $session[config('kiosk.reset_session_photo_key')] = session(config('kiosk.reset_session_photo_key'));
        $session[config('kiosk.reset_session_questions_key')] = $presented;

        $submitHeaders = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/reset/submit');
        $this->kioskRequest()->withSession($session)
            ->withHeaders($submitHeaders)
            ->post(route('kiosk.reset.submit'), ['answers' => $answers])
            ->assertRedirect(route('kiosk.reset.index'));

        $request = PasswordResetRequest::query()->first();
        $this->assertNotNull($request);
        $this->assertSame(PasswordResetRequestStatus::Failed, $request->status);
        Queue::assertNothingPushed();
    }
}

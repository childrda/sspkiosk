<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StudentPhotoType;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AdminStudentService;
use App\Services\AuditLogService;
use App\Services\ResetAttemptLimiterService;
use App\Services\RosterComparisonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function __construct(
        private readonly AdminStudentService $adminStudents,
        private readonly ResetAttemptLimiterService $attemptLimiter,
        private readonly AuditLogService $auditLog,
        private readonly RosterComparisonService $rosterComparison,
    ) {}

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        $students = Student::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($inner) use ($query): void {
                    $inner->where('email', 'like', '%'.$query.'%')
                        ->orWhere('name', 'like', '%'.$query.'%')
                        ->orWhere('google_sub', 'like', '%'.$query.'%');
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students.index', [
            'students' => $students,
            'query' => $query,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rowCount = Student::query()->count();

        $this->auditLog->logAdmin(
            'admin.students.exported',
            (int) $request->user()->id,
            'student',
            null,
            ['row_count' => $rowCount],
            $request,
        );

        $filename = 'registered-students-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'email',
                'name',
                'school',
                'grade',
                'org_unit_path',
                'registered_at',
                'questions_count',
                'has_registration_photo',
                'reset_enabled',
                'reset_requests_count',
                'last_reset_at',
            ]);

            Student::query()
                ->withCount(['challengeQuestions', 'passwordResetRequests'])
                ->withExists([
                    'photos as has_registration_photo' => fn ($query) => $query->where('type', StudentPhotoType::Registration),
                ])
                ->withMax('passwordResetRequests as last_reset_at', 'requested_at')
                ->orderBy('name')
                ->chunk(500, function ($students) use ($handle): void {
                    foreach ($students as $student) {
                        fputcsv($handle, [
                            $student->email,
                            $student->name,
                            $student->school,
                            $student->grade,
                            $student->org_unit_path,
                            $student->registered_at?->toIso8601String(),
                            $student->challenge_questions_count,
                            $student->has_registration_photo ? '1' : '0',
                            $student->reset_enabled ? '1' : '0',
                            $student->password_reset_requests_count,
                            $student->last_reset_at,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function showRosterCompare(): View
    {
        return view('admin.students.roster-compare');
    }

    public function rosterCompare(Request $request): View|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'roster' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $validator->validate();

        /** @var UploadedFile $file */
        $file = $request->file('roster');
        $handle = fopen($file->getRealPath(), 'r');
        $headerRow = $handle !== false ? fgetcsv($handle) : false;

        if ($handle !== false) {
            fclose($handle);
        }

        if ($headerRow === false) {
            throw ValidationException::withMessages([
                'roster' => 'The roster file is empty.',
            ]);
        }

        if ($this->rosterComparison->findEmailColumnIndex($headerRow) === null) {
            $headers = $this->rosterComparison->readableHeaders($headerRow);

            throw ValidationException::withMessages([
                'roster' => 'The CSV must include an email column. Headers found: '.implode(', ', $headers),
            ]);
        }

        $comparison = $this->rosterComparison->compare($file);

        $this->auditLog->logAdmin(
            'admin.students.roster_compared',
            (int) $request->user()->id,
            'student',
            null,
            [
                'in_roster_not_registered_count' => count($comparison['in_roster_not_registered']),
                'registered_not_in_roster_count' => count($comparison['registered_not_in_roster']),
                'both_count' => $comparison['both_count'],
            ],
            $request,
        );

        return view('admin.students.roster-compare-results', [
            'comparison' => $comparison,
        ]);
    }

    public function show(Student $student): View
    {
        $student->load([
            'challengeQuestions',
            'photos',
            'passwordResetRequests' => fn ($query) => $query->with('kiosk')->latest('requested_at')->limit(20),
        ]);

        $registrationPhoto = $student->photos
            ->firstWhere('type', StudentPhotoType::Registration);

        return view('admin.students.show', [
            'student' => $student,
            'registrationPhoto' => $registrationPhoto,
            'failedAttemptsToday' => $this->attemptLimiter->failedAttemptsForStudentToday($student),
            'isLockedOut' => $this->attemptLimiter->isStudentLockedOut($student),
        ]);
    }

    public function disableReset(Request $request, Student $student): RedirectResponse
    {
        $this->adminStudents->setResetEnabled($student, false, (int) $request->user()->id);

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', 'Kiosk password reset disabled for this student.');
    }

    public function enableReset(Request $request, Student $student): RedirectResponse
    {
        $this->adminStudents->setResetEnabled($student, true, (int) $request->user()->id);

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', 'Kiosk password reset enabled for this student.');
    }
}

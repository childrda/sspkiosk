<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PasswordResetRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Services\AdminKioskService;
use App\Services\ResetAttemptLimiterService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AdminKioskService $kiosks,
        private readonly ResetAttemptLimiterService $attemptLimiter,
    ) {}

    public function index(): View
    {
        $requestCounts = collect(PasswordResetRequestStatus::cases())
            ->mapWithKeys(fn (PasswordResetRequestStatus $status) => [
                $status->value => PasswordResetRequest::query()->where('status', $status)->count(),
            ]);

        $kioskModels = Kiosk::query()->orderBy('name')->get();

        return view('admin.dashboard', [
            'requestCounts' => $requestCounts,
            'registeredStudents' => Student::query()->whereNotNull('registered_at')->count(),
            'resetDisabledStudents' => Student::query()->where('reset_enabled', false)->count(),
            'kiosks' => $kioskModels,
            'onlineKiosks' => $kioskModels->filter(fn (Kiosk $kiosk): bool => $this->kiosks->isOnline($kiosk))->count(),
            'adEnabled' => (bool) config('active-directory.enabled'),
            'adConfigured' => app(\App\Services\ActiveDirectoryService::class)->isConfigured(),
            'recentPending' => PasswordResetRequest::query()
                ->with(['student', 'kiosk'])
                ->where('status', PasswordResetRequestStatus::Pending)
                ->latest('requested_at')
                ->limit(10)
                ->get(),
            'officeVerificationCount' => PasswordResetRequest::query()
                ->where('status', PasswordResetRequestStatus::NeedsOfficeVerification)
                ->count(),
            'queueDepth' => DB::table('jobs')->count(),
            'failedJobCount' => DB::table('failed_jobs')->count(),
        ]);
    }

    public function failedAttempts(): View
    {
        $failedToday = PasswordResetRequest::query()
            ->with(['student', 'kiosk'])
            ->where('status', PasswordResetRequestStatus::Failed)
            ->where('created_at', '>=', now()->startOfDay())
            ->latest('created_at')
            ->get();

        $studentLockouts = Student::query()
            ->whereNotNull('registered_at')
            ->get()
            ->filter(fn (Student $student): bool => $this->attemptLimiter->isStudentLockedOut($student));

        $kioskLockouts = Kiosk::query()
            ->get()
            ->filter(fn (Kiosk $kiosk): bool => $this->attemptLimiter->isKioskLockedOut($kiosk));

        $officeRejectionsToday = PasswordResetRequest::query()
            ->with(['student', 'kiosk'])
            ->where('status', PasswordResetRequestStatus::Denied)
            ->whereNotNull('escalated_at')
            ->whereNull('denied_by_slack_user_id')
            ->where('denied_at', '>=', now()->startOfDay())
            ->latest('denied_at')
            ->get();

        $splitDirectoryCases = PasswordResetRequest::query()
            ->with(['student', 'kiosk', 'activeRevision'])
            ->where(function ($query): void {
                $query->where('status', PasswordResetRequestStatus::PartiallyCompleted)
                    ->orWhere(function ($failed): void {
                        $failed->where('status', PasswordResetRequestStatus::Failed)
                            ->whereNotNull('directory_results');
                    });
            })
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->filter(function (PasswordResetRequest $request): bool {
                $results = $request->directory_results['results'] ?? [];
                $statuses = collect($results)->pluck('status');

                return $statuses->contains('success') && $statuses->contains('failed');
            });

        return view('admin.reports.failed-attempts', [
            'failedToday' => $failedToday,
            'officeRejectionsToday' => $officeRejectionsToday,
            'splitDirectoryCases' => $splitDirectoryCases,
            'studentLockouts' => $studentLockouts,
            'kioskLockouts' => $kioskLockouts,
            'maxStudentAttempts' => config('student-password-reset.max_failed_attempts_per_student'),
            'maxKioskAttempts' => config('student-password-reset.max_failed_attempts_per_kiosk'),
        ]);
    }
}

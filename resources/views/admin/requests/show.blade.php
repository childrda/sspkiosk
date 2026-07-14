@extends('layouts.admin')

@section('title', 'Request #'.$resetRequest->id)

@section('content')
    @php
        use App\Enums\PasswordResetRequestStatus;
    @endphp

    <h1>Reset request #{{ $resetRequest->id }}</h1>

    @if (session('office_password'))
        <div class="flash flash-secret">
            <strong>Give this password to the student. It will not be shown again.</strong>
            <p style="font-family:monospace;font-size:1.1rem;margin:0.75rem 0;">{{ session('office_password') }}</p>
            <p class="muted" style="margin:0;">
                The Google password reset runs in the background and may take a few seconds.
                Refresh this page before handing the password to the student.
                @if ($resetRequest->status === PasswordResetRequestStatus::ApprovedProcessing)
                    Current status: waiting for Google reset.
                @elseif ($resetRequest->status === PasswordResetRequestStatus::Completed && $resetRequest->google_reset_success)
                    Google reset completed — the password should work now.
                @elseif ($resetRequest->status === PasswordResetRequestStatus::Failed || $resetRequest->google_reset_success === false)
                    <strong style="color:#991b1b;">Google reset failed.</strong> Do not give the student this password. Use Retry reset below.
                @endif
            </p>
        </div>
    @endif

    <div class="card">
        <p><strong>Status:</strong> <span class="badge badge-{{ $resetRequest->status->value }}">{{ str_replace('_', ' ', $resetRequest->status->value) }}</span></p>
        <p><strong>Student:</strong> <a href="{{ route('admin.students.show', $resetRequest->student) }}">{{ $resetRequest->student->name }}</a> ({{ $resetRequest->student->email }})</p>
        <p><strong>Kiosk:</strong> <a href="{{ route('admin.kiosks.show', $resetRequest->kiosk) }}">{{ $resetRequest->kiosk->name }}</a></p>
        <p><strong>Challenge score:</strong> {{ $resetRequest->challenge_score }} / {{ count($resetRequest->challenge_questions_presented ?? []) }}</p>
        <p><strong>Requested:</strong> {{ $resetRequest->requested_at?->toDateTimeString() }}</p>
        <p><strong>Expires:</strong> {{ $resetRequest->expires_at?->toDateTimeString() }}</p>
        <p><strong>Reset mode:</strong> {{ $resetRequest->reset_mode ?? '—' }}</p>
        <p><strong>Password mode:</strong> {{ $resetRequest->password_mode ?? '—' }}</p>
        <p><strong>Password origin:</strong> {{ $resetRequest->password_origin ?? '—' }}</p>
        <p><strong>Force change at next login:</strong> {{ $resetRequest->force_change_at_next_login === null ? '—' : ($resetRequest->force_change_at_next_login ? 'Yes' : 'No') }}</p>
        @if ($resetRequest->superseded_student_selected_password)
            <p><strong>Student-selected password superseded:</strong> Yes (office-generated temporary replacement)</p>
        @endif
        <p><strong>Retry available:</strong> {{ $resetRequest->retry_available ? 'Yes' : 'No' }}</p>
        @if ($resetRequest->escalated_at)
            <p><strong>Escalated in Slack:</strong> {{ $resetRequest->escalated_at->toDateTimeString() }}
                @if ($resetRequest->escalated_by_slack_user_id)
                    by {{ $resetRequest->escalated_by_slack_user_id }}
                @endif
            </p>
        @endif
        @if ($resetRequest->office_verification_expires_at)
            <p><strong>Office verification window:</strong>
                {{ $resetRequest->office_verification_expires_at->toDateTimeString() }}
                ({{ $resetRequest->office_verification_expires_at->isPast() ? 'expired' : $resetRequest->office_verification_expires_at->diffForHumans() }})
            </p>
        @endif
        @if ($resetRequest->office_verified_at)
            <p><strong>Office verified:</strong> {{ $resetRequest->office_verified_at->toDateTimeString() }}
                @if ($resetRequest->officeVerifiedBy)
                    by {{ $resetRequest->officeVerifiedBy->name }}
                @endif
            </p>
        @endif
        @if ($resetRequest->office_verification_notes)
            <p><strong>Office notes:</strong> {{ $resetRequest->office_verification_notes }}</p>
        @endif
        <p><strong>Pending password type:</strong> {{ $resetRequest->pending_password_type ?? '—' }}</p>
        <p><strong>Encrypted pending password on file:</strong> {{ $resetRequest->hasEncryptedPendingPassword() ? 'Yes' : 'No' }}</p>
        @if ($resetRequest->approved_at)
            <p><strong>Approved:</strong> {{ $resetRequest->approved_at->toDateTimeString() }} (Slack {{ $resetRequest->approved_by_slack_user_id }})</p>
        @endif
        @if ($resetRequest->denied_at)
            <p><strong>Denied:</strong> {{ $resetRequest->denied_at->toDateTimeString() }}
                @if ($resetRequest->denial_reason)
                    — {{ $resetRequest->denial_reason }}
                @endif
            </p>
        @endif
        @if ($resetRequest->google_reset_attempted_at)
            <p><strong>Google reset (legacy):</strong> {{ $resetRequest->google_reset_success ? 'Success' : 'Failed' }}
                @if ($resetRequest->google_error_message)
                    — {{ $resetRequest->google_error_message }}
                @endif
            </p>
        @endif
        @if (is_array($resetRequest->directory_results))
            <h2 style="margin-top:1.25rem;font-size:1.1rem;">Directory results</h2>
            <p class="muted">Required: {{ implode(', ', $resetRequest->directory_results['required_directories'] ?? []) ?: '—' }}</p>
            <ul>
                @foreach (($resetRequest->directory_results['results'] ?? []) as $directory => $result)
                    <li>
                        <strong>{{ str_replace('_', ' ', $directory) }}:</strong>
                        {{ $result['status'] ?? '—' }}
                        @if (! empty($result['reason']))
                            ({{ $result['reason'] }})
                        @endif
                        @if (! empty($result['retry_mode']) && ($result['status'] ?? null) === 'failed')
                            — retry: {{ $result['retry_mode'] }}
                        @endif
                        @if (! empty($result['attempts']))
                            — attempts: {{ $result['attempts'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($resetRequest->status === PasswordResetRequestStatus::NeedsOfficeVerification)
        <div class="card">
            <h2>Office verification</h2>
            <p>Compare the registration photo and kiosk photo side by side before verifying identity.</p>
            <p class="muted" style="color:#9a3412;">
                This action will replace the password selected by the student with a new temporary password.
                The student will be required to change it at the next login in both Google Workspace and Windows.
                Student-selected passwords are never shown to staff.
            </p>

            <form method="post" action="{{ route('admin.requests.office-verify', $resetRequest) }}" style="margin-bottom:1.5rem;" onsubmit="return confirm('Verify this student and reset their password? This cannot be undone.');">
                @csrf
                <label>
                    Verification notes (optional)
                    <textarea name="notes" rows="3" style="width:100%;max-width:36rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn btn-primary">Verify identity &amp; reset password</button>
            </form>

            <form method="post" action="{{ route('admin.requests.office-reject', $resetRequest) }}" onsubmit="return confirm('Reject this request? The student will not receive a password reset.');">
                @csrf
                <label>
                    Rejection reason (required)
                    <textarea name="reason" rows="3" required style="width:100%;max-width:36rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn btn-danger">Reject request</button>
            </form>
        </div>
    @endif

    @if ($resetRequest->status === PasswordResetRequestStatus::Failed)
        <div class="card">
            <h2>Retry directory reset</h2>
            <p>Retry mints a new office-generated temporary password and writes it to Google Workspace and Active Directory.</p>
            <form method="post" action="{{ route('admin.requests.retry-reset', $resetRequest) }}" onsubmit="return confirm('Retry the directory password reset with a new password?');">
                @csrf
                <button type="submit" class="btn btn-primary">Retry reset</button>
            </form>
        </div>
    @endif

    @if ($resetRequest->status === PasswordResetRequestStatus::PartiallyCompleted)
        <div class="card">
            <h2>Partial completion</h2>
            <p>
                At least one directory succeeded and at least one did not. Student-selected passwords are retained for
                recovery (Prompt 2). Active Directory policy rejection means the student must choose a different password.
            </p>
            @if ($resetRequest->retry_available)
                <p class="muted">A manual or automatic retry remains available for the current encrypted password.</p>
            @endif
        </div>
    @endif

    <div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;">
        @if ($registrationPhoto)
            <div>
                <h2>Registration photo</h2>
                <img class="photo-thumb" src="{{ route('admin.photos.show', $registrationPhoto) }}" alt="Registration photo">
            </div>
        @endif
        @if ($resetRequest->resetPhoto)
            <div>
                <h2>Kiosk reset photo</h2>
                <img class="photo-thumb" src="{{ route('admin.photos.show', $resetRequest->resetPhoto) }}" alt="Reset request photo">
            </div>
        @endif
    </div>
@endsection

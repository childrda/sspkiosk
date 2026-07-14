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

    @if (! empty($showRetryDirectories))
        <div class="card" style="border-left:4px solid #2563eb;">
            <h2>Retry failed directories</h2>
            <p>Retries the <strong>same</strong> encrypted password against directories that have not succeeded. Successful directories are never rewritten.</p>
            <form method="post" action="{{ route('admin.requests.retry-reset', $resetRequest) }}" onsubmit="return confirm('Retry failed directories with the existing password?');">
                @csrf
                <button type="submit" class="btn btn-primary">Retry failed directories</button>
            </form>
        </div>
    @endif

    @if (! empty($showReplacePassword))
        <div class="card" style="border-left:4px solid #c2410c;">
            <h2>Replace password in all directories</h2>
            <p style="color:#9a3412;">
                Google already accepted the student's previous password. Replacing it will change the password in
                Google Workspace and Active Directory. The student's currently working Google password will stop working.
            </p>
            <p>After confirmation, the student must return to the kiosk and choose a <strong>different</strong> password. This is not a one-click Slack action.</p>
            <form method="post" action="{{ route('admin.requests.start-replacement', $resetRequest) }}">
                @csrf
                <label>
                    Type <code>REPLACE PASSWORD</code> to confirm
                    <input type="text" name="confirmation" required autocomplete="off" style="width:100%;max-width:24rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;">
                </label>
                <label>
                    Reason (required)
                    <textarea name="reason" rows="3" required style="width:100%;max-width:36rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn btn-danger">Start password replacement</button>
            </form>
        </div>
    @endif

    @if ($resetRequest->status === PasswordResetRequestStatus::Failed && empty($showRetryDirectories) && empty($showReplacePassword))
        <div class="card">
            <h2>Office temporary re-issue</h2>
            <p>Mints a new office-generated temporary password and writes it to both directories (forces change at next login).</p>
            <form method="post" action="{{ route('admin.requests.retry-reset', $resetRequest) }}" onsubmit="return confirm('Issue a new office temporary password?');">
                @csrf
                <button type="submit" class="btn btn-primary">Issue office temporary password</button>
            </form>
        </div>
    @endif

    @if ($resetRequest->status === PasswordResetRequestStatus::AwaitingPasswordReselection)
        <div class="card">
            <h2>Awaiting kiosk re-selection</h2>
            <p>
                The password you selected was accepted by Google but could not be used for your school computer account.
                Have the student return to the kiosk, look up again if needed, and choose a different password so the same
                password can be applied to both Google and Windows.
            </p>
        </div>
    @endif

    @if ($resetRequest->status === PasswordResetRequestStatus::PartiallyCompleted)
        <div class="card">
            <h2>Partial completion</h2>
            <p>At least one directory succeeded and at least one did not. Use retry or replace above — they have different consequences.</p>
        </div>
    @endif

    @if (! empty($showCancel))
        <div class="card" style="border-left:4px solid #6b7280;">
            <h2>Cancel / abandon request</h2>
            <p>Deletes the active encrypted password, stops directory execution, and tells staff the student must start a new request.</p>
            <form method="post" action="{{ route('admin.requests.cancel', $resetRequest) }}">
                @csrf
                <label>
                    Type <code>CANCEL REQUEST</code> to confirm
                    <input type="text" name="confirmation" required autocomplete="off" style="width:100%;max-width:24rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;">
                </label>
                <label>
                    Reason (required)
                    <textarea name="reason" rows="2" required style="width:100%;max-width:36rem;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                </label>
                <button type="submit" class="btn btn-secondary">Cancel request</button>
            </form>
        </div>
    @endif

    <div class="card">
        <h2>Revision history</h2>
        @forelse ($resetRequest->revisions as $revision)
            <div style="border-top:1px solid #e5e7eb;padding:0.75rem 0;">
                <p>
                    <strong>Revision {{ $revision->revision_number }}</strong>
                    <span class="badge badge-{{ $revision->status->value }}">{{ str_replace('_', ' ', $revision->status->value) }}</span>
                </p>
                <p class="muted">
                    Origin: {{ $revision->password_origin ?? '—' }}
                    · Mode: {{ $revision->password_mode ?? '—' }}
                    · Force change: {{ $revision->force_change_at_next_login === null ? '—' : ($revision->force_change_at_next_login ? 'yes' : 'no') }}
                    · Created: {{ $revision->created_at?->toDateTimeString() }}
                    @if ($revision->superseded_at)
                        · Superseded: {{ $revision->superseded_at->toDateTimeString() }}
                    @endif
                    · Retry: {{ $revision->retry_available ? 'yes' : 'no' }}
                </p>
                @if (is_array($revision->directory_results['results'] ?? null))
                    <ul>
                        @foreach ($revision->directory_results['results'] as $directory => $result)
                            <li>
                                {{ str_replace('_', ' ', $directory) }}:
                                {{ $result['status'] ?? '—' }}
                                @if (! empty($result['reason'])) ({{ $result['reason'] }}) @endif
                                @if (! empty($result['attempts'])) — attempts {{ $result['attempts'] }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <p class="muted">No revisions recorded yet.</p>
        @endforelse
    </div>

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

Build Prompt: Resolve the Office Verification Dead-End
Context
This is sspkiosk, a Laravel 12 self-service student password reset kiosk for a K-12 division. Students authenticate at a physical kiosk, answer challenge questions, and submit a photo; a technician approves or denies via Slack interactive buttons.
Slack approvers have a third button — "Needs Office Verification" — for the case where the photo or challenge answers are ambiguous and the student needs to be identified in person by a human before their password is reset.
That button leads nowhere. It is a one-way door. This task builds the exit.
Current broken behavior
SlackApprovalService::processOfficeVerification() (around line 257) is the only place PasswordResetRequestStatus::NeedsOfficeVerification is ever assigned. Nothing anywhere in the codebase transitions a request out of that status. There is no admin route, no controller method, no service call, no Slack action. It is terminal by omission.
Read that method carefully before writing anything, because its side effects constrain the whole design:
php$request->update([
    'status' => PasswordResetRequestStatus::NeedsOfficeVerification,
    'denied_by_slack_user_id' => $slackUserId,
    'denied_at' => now(),
    'denial_reason' => 'Escalated for office verification',
]);

$this->pendingPasswords->delete($request, 'denial');
Three consequences that must drive the implementation:

The pending password is destroyed. PendingPasswordService::delete() is called with reason 'denial'. Whatever password the student selected (in student_selected_pending_approval mode) or was generated for them (temporary_generated mode) is gone. ResetGooglePasswordJob short-circuits with "Pending password unavailable." if it runs against this request. You cannot simply flip the status to ApprovedProcessing and dispatch the job — it will fail. A new password must be minted at verification time.
The request is stamped as denied. denied_at and denied_by_slack_user_id are set, and denial_reason is a human sentence. This is semantically wrong — the request wasn't denied, it was escalated — and it will corrupt any denial reporting. Note that admin/reports/failed-attempts.blade.php and DashboardController::failedAttempts() exist; check whether they key off denied_at and would miscount escalations as denials.
assertCanDecide() only permits transitions from Pending. So once a request is in NeedsOfficeVerification, the Slack buttons are inert — alreadyDecidedResponse() fires. Resolution must therefore happen in the admin panel, not in Slack. That is the correct place anyway: the whole point of office verification is that a staff member is physically looking at the student, and the front-office/tech staff who do that are admin-panel users.

Also note PasswordResetRequestStatus has an Expired case that appears unused, and a @deprecated Approved case. Don't add to the deprecated one.

What to build
1. Model the escalation honestly
Stop reusing the denial columns for escalation.
Migration add_office_verification_fields_to_password_reset_requests_table:

escalated_at (nullable timestamp)
escalated_by_slack_user_id (nullable string)
office_verified_at (nullable timestamp)
office_verified_by_user_id (nullable FK → users.id, nullOnDelete)
office_verification_notes (nullable text) — staff records how identity was confirmed
office_verification_expires_at (nullable timestamp)

Rewrite processOfficeVerification() to populate escalated_at / escalated_by_slack_user_id and leave denied_at and denied_by_slack_user_id null. Set office_verification_expires_at = now()->addHours(config('student-password-reset.office_verification_expires_hours')). Keep the pendingPasswords->delete() call but change the reason string from 'denial' to 'escalation' so the audit trail is truthful about why the password was destroyed. Check PendingPasswordService::delete() — if it validates the reason against an allowlist or writes it to pending_password_deleted_reason, add 'escalation' there.
Do not backfill existing NeedsOfficeVerification rows into the new columns blindly — write the migration so that existing rows with status = needs_office_verification and denial_reason = 'Escalated for office verification' get escalated_at = denied_at, escalated_by_slack_user_id = denied_by_slack_user_id, and then have denied_at/denied_by_slack_user_id/denial_reason nulled out. There are stranded requests in production right now; this migration is what frees them.
2. Add an expiry path
An escalated request should not be verifiable forever. If a student never shows up at the office, the request must age out.
Add to config/student-password-reset.php:
php'office_verification_expires_hours' => (int) env('OFFICE_VERIFICATION_EXPIRES_HOURS', 48),
'office_verification_max_queue_depth' => (int) env('OFFICE_VERIFICATION_MAX_QUEUE_DEPTH', 25),
Add app/Console/Commands/ExpireStaleResetRequestsCommand.php (ssp:expire-requests), scheduled hourly in routes/console.php alongside the existing ssp:prune-nonces. It transitions NeedsOfficeVerification requests whose office_verification_expires_at has passed → PasswordResetRequestStatus::Expired, deletes any pending password, and audit-logs request.office_verification.expired. This finally gives the Expired enum case a purpose.
Follow the existing PruneUsedNoncesCommand for structure — it's the closest model in the codebase.
3. Admin resolution actions
Routes in routes/admin.php, inside the existing ['auth', 'admin'] group:
phpRoute::post('requests/{passwordResetRequest}/office-verify', [PasswordResetRequestController::class, 'officeVerify'])
    ->name('requests.office-verify');
Route::post('requests/{passwordResetRequest}/office-reject', [PasswordResetRequestController::class, 'officeReject'])
    ->name('requests.office-reject');
Route::post('requests/{passwordResetRequest}/retry-reset', [PasswordResetRequestController::class, 'retryReset'])
    ->name('requests.retry-reset');
Create app/Services/OfficeVerificationService.php and keep the controller thin — this codebase consistently pushes logic into services (AdminKioskService, SlackApprovalService, AdminStudentService), so match that.
verify(PasswordResetRequest $request, User $admin, ?string $notes): string (returns the plaintext password)
Guard hard. Wrap in DB::transaction with lockForUpdate() on the request — this mirrors ResetGooglePasswordJob and prevents a double-click from double-resetting.

Abort 409 unless status === NeedsOfficeVerification.
Abort 409 if office_verification_expires_at has passed (tell the admin to have the student submit a new request).
Guard against a dead queue worker (see below) before minting anything.
Mint a fresh password. The old one is gone. Use the existing PasswordGeneratorService (which already has a config/words/default.php wordlist and a unit test). Regardless of what RESET_PASSWORD_MODE the original request used, an office-verified reset produces a generated temporary password — the student is standing at a counter, not at a kiosk, so there is no UI for them to choose one. Store it via PendingPasswordService::store($request, $plain, PendingPasswordType::Generated) (confirm the correct enum case in app/Enums/PendingPasswordType.php).
Force change at next login, unconditionally, regardless of config('student-password-reset.google_force_change_at_next_login.*'). A password handed to a student verbally or on paper by a staff member has left the encrypted-at-rest boundary and must be rotated on first use. ResetGooglePasswordJob::forceChangeAtNextLogin() currently derives this from pending_password_type + config — add an explicit override so an office-verified request always forces the change.
Set office_verified_at, office_verified_by_user_id, office_verification_notes, status = ApprovedProcessing, and clear google_reset_attempted_at to null (it should already be null, but the job's idempotency guard keys off it — be certain).
Audit-log admin.request.office_verified via AuditLogService::logAdmin() with the admin user id, the request id, and whether notes were supplied. Never log the password.
Dispatch ResetGooglePasswordJob.

reject(PasswordResetRequest $request, User $admin, string $reason): void
The student showed up and could not be identified, or the request is fraudulent. Transition to Denied, set denied_at / denial_reason (legitimately, this time), leave denied_by_slack_user_id null and instead record the admin. Audit-log admin.request.office_rejected.
On reject, do not auto-disable the student's resets (students.reset_enabled). A failed in-person identity check is usually an honest mixup — a substitute at the desk, a sibling, a kid who forgot their own birthday — not an attack, and auto-disabling turns a bad afternoon into a help-desk ticket. Instead, fire the distinct audit event and add office rejections to the existing admin/reports/failed-attempts.blade.php report so a pattern across multiple attempts on one student becomes visible to a human. Leave the disable action where it already is: manual, in AdminStudentService.
retry(PasswordResetRequest $request, User $admin): string
ResetGooglePasswordJob has $tries = 3; after three failures the request sits at status = Failed with a dead job in failed_jobs and no way forward. That is a second dead end — the exact bug class this task exists to eliminate.
Abort 409 unless status === Failed. Re-mint a password, clear google_reset_attempted_at, set status = ApprovedProcessing, re-dispatch the job, audit-log admin.request.reset_retried. Return the new plaintext for one-time display.
4. The queue is async — build for it
QUEUE_CONNECTION=database in production — confirmed in .env.example:38, config/queue.php:16, and the sspkiosk-queue systemd unit in docs/INSTALL-UBUNTU.md:786. Note that phpunit.xml:31 overrides QUEUE_CONNECTION=sync for tests only, so ResetGooglePasswordJob runs inline in the test suite but asynchronously in production.
Build for async. Do not let a green test suite convince you the timing gap doesn't exist — a test asserting status === Completed immediately after verify will pass under sync and be wrong about production.
Guard against a dead worker. If sspkiosk-queue is stopped, verify() will mint a password, flash it to staff, dispatch a job nobody drains, and hand the student a credential that never becomes valid. Before minting, check queue depth (DB::table('jobs')->count()); if it exceeds config('student-password-reset.office_verification_max_queue_depth'), block the verify with a clear error telling the admin the queue worker may be down. Mint nothing, dispatch nothing. Also surface queue depth and failed_jobs count on the admin dashboard.
Where the admin actually sees the password. This is the crux of the feature. After verify(), the job runs asynchronously, but the staff member at the counter needs the new password to give to the student:

Flash the plaintext to the session on successful verify (->with('office_password', $plain)), following the exact pattern KioskController::rotateSecret() already uses for kiosk_secret.
Render it once on admin/requests/show.blade.php in a prominent panel: "Give this to the student. It will not be shown again."
Because the job is queued, the password won't be live in Google for a few seconds. Say so in the UI. The panel must show job status — poll or instruct the staff member to refresh, and display google_reset_success / google_error_message when it lands. Nothing is worse for a front-office workflow than handing a student a password that doesn't work yet and having them walk away.
If the job fails, the flashed password is a credential the staff member holds that does not work. The show page must surface status = Failed / google_reset_success = false unmistakably, with the retry action from item 3.

5. UI
resources/views/admin/requests/show.blade.php currently renders status/student/kiosk/photo and has no action buttons at all. Add an actions card, gated on status:

@if ($resetRequest->status === PasswordResetRequestStatus::NeedsOfficeVerification) → show "Verify identity & reset password" (with a notes textarea) and "Reject request" (with a required reason). Both POST with @csrf. Both need a JS confirm() — these are irreversible.
@if ($resetRequest->status === PasswordResetRequestStatus::Failed) → show "Retry reset".
Show the escalation context: who escalated it in Slack, when, and how long until it expires (office_verification_expires_at->diffForHumans()). Staff need to know if the window is about to close.
Display the student's registration photo next to the reset-request photo, side by side. This is the actual identity-verification act — the whole feature is a human comparing two faces. StudentPhoto / StudentPhotoType and admin.photos.show already exist; use them. Right now the show page only renders $resetRequest->resetPhoto and not the registration photo, which makes in-person verification harder than it needs to be.
Add a filter/tab to admin/requests/index.blade.php for needs_office_verification, and surface the count on the dashboard. Staff must be able to find these — a request stranded in a status nobody can see is barely better than a dead end. The badge CSS class .badge-needs_office_verification already exists in layouts/admin.blade.php.

6. Slack message consistency
refreshSlackMessage($request, 'Needs office verification', $slackUserId) updates the Slack thread on escalation. After an admin verifies or rejects, update the Slack message again so the technician who escalated it sees the outcome. SlackApprovalService has the message-refresh plumbing; expose a method the OfficeVerificationService can call (SlackApprovalService::notifyOfficeOutcome() or similar). Otherwise the Slack thread lies forever, and the tech who escalated never learns what happened.

Tests
Add tests/Feature/OfficeVerificationTest.php. Existing AdminDashboardTest.php and SlackInteractionTest.php are the closest models — reuse User::factory()->admin() and the PasswordResetRequestFactory.

Slack escalation sets escalated_at and leaves denied_at null (regression guard against the current bug).
Slack escalation deletes the pending password.
Admin verify on a NeedsOfficeVerification request → status becomes ApprovedProcessing, a new encrypted pending password exists, ResetGooglePasswordJob is dispatched (Queue::fake() + assertPushed), and the plaintext is flashed to session exactly once.
Verify forces changePasswordAtNextLogin = true even when config says otherwise for that pending-password type.
Verify on a request in any other status → 409.
Verify on an expired escalation → 409, no job dispatched.
Verify while queue depth exceeds the threshold is blocked — mints no password, dispatches no job.
Reject → status Denied, denial_reason persisted, no job dispatched, students.reset_enabled unchanged.
Retry on a Failed request mints a fresh password and re-dispatches.
Retry on any non-Failed status → 409.
Double-submit (two rapid verifies) results in exactly one job dispatch.
ssp:expire-requests transitions a stale escalation to Expired and leaves an in-window one alone.
Non-admin user gets 403 on all three routes.
End-to-end: a request escalated via Slack can be fully resolved by an admin and reach Completed. This is the test that proves the dead-end is gone; it should be impossible to write against the current codebase.


Constraints

Do not weaken assertCanDecide() to let Slack resolve office verification. In-person identity confirmation belongs to the admin panel, and the Slack approver is not the person looking at the student.
Do not reuse the destroyed pending password. Mint fresh.
Do not log, audit, or Slack-post the plaintext password. It is shown once in the admin UI and nowhere else. This system is FERPA-scoped and handles student PII.
Do not touch the kiosk heartbeat/HMAC subsystem — it was just fixed and is verified green.
Preserve the existing audit-log patterns (AuditLogService::logAdmin / logTech / logSystem).
Follow the existing service-layer architecture; do not put business logic in controllers.

Deliverables

Migration adding the office-verification columns + backfill freeing existing stranded requests
app/Services/OfficeVerificationService.php (verify / reject / retry)
PasswordResetRequestController::officeVerify() / officeReject() / retryReset() + routes
Rewritten SlackApprovalService::processOfficeVerification() + an outcome-notification method
ExpireStaleResetRequestsCommand + hourly schedule
PasswordGeneratorService / PendingPasswordService / ResetGooglePasswordJob adjustments for the forced-change override
Updated admin/requests/show.blade.php (side-by-side photos, action buttons, one-time password panel, job status, retry) and admin/requests/index.blade.php (filter); queue depth + failed-jobs count on the dashboard
Office rejections added to admin/reports/failed-attempts.blade.php
tests/Feature/OfficeVerificationTest.php
Config keys + .env.example entries

Work in order. Run the existing suite after the migration and the SlackApprovalService rewrite — SlackInteractionTest and SlackApprovalMessageTest both exercise the escalation path and will need updating, since the column semantics change. Those tests currently encode the bug; make them green by updating their assertions to the new column semantics, not by restoring the old denied_at writes. Confirm they pass before building the admin side.
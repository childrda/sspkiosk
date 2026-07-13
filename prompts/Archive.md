Build Prompt: Kiosk Archive, Student Roster Export, and Label Printing
Context
This is sspkiosk, a Laravel 12 self-service student password reset kiosk for a K-12 division (Louisa County Public Schools). Students authenticate at a physical kiosk via Google, answer challenge questions, submit a photo, and a technician approves via Slack. The system is FERPA-scoped and handles student PII.
Two prior fixes are complete and must not be disturbed: the kiosk HMAC heartbeat agent (agent/, KioskSecurityService, ValidateKioskRequest) and the office-verification resolution flow (OfficeVerificationService). Both have passing regression suites. Treat them as frozen.
This task delivers three independent features. Build them in the order given — the first two are small and low-risk, the third carries a security consideration that needs care.

Feature 1 — Archive kiosks instead of blocking deletion
Current behavior
KioskController::destroy() hard-blocks deletion whenever a kiosk has any reset history:
phpif ($kiosk->passwordResetRequests()->exists()) {
    return redirect()->route('admin.kiosks.show', $kiosk)
        ->with('error', 'This kiosk cannot be deleted because it has password reset request history.');
}
$kiosk->delete();
This is a deliberate guard protecting the audit trail, not a bug — but it means a decommissioned kiosk is permanently stuck in the admin list forever. Staff want it gone; the audit trail needs it kept. Both are right.
Build
Soft-delete + archive, not hard-delete. History is preserved; the kiosk leaves the active list.
Migration add_soft_deletes_to_kiosks_table → $table->softDeletes();
app/Models/Kiosk.php → add the SoftDeletes trait.
Critical: PasswordResetRequest::kiosk() must become ->belongsTo(Kiosk::class)->withTrashed(). Without this, admin/requests/show.blade.php fatals on $resetRequest->kiosk->name for any archived kiosk, and admin/kiosks/show.blade.php breaks. Grep for every ->kiosk access and confirm each survives an archived parent. This is the single most likely way to break production with this change.
Add AdminKioskService::archive(Kiosk $kiosk, int $adminUserId): void — wrap in DB::transaction:

Set status = KioskStatus::Disabled
Null secret_hash — revoke the device credential so an archived kiosk's agent can no longer authenticate. KioskSecurityService::assertKioskIsOperational() will then reject it with kiosk_not_enrolled, which the heartbeat agent already handles by backing off to ADMIN_STATE_INTERVAL. Good.
Delete its enrollmentCodes() and usedNonces() rows (hard delete — these are ephemeral, not audit records)
$kiosk->delete() (soft)
Audit-log admin.kiosk.archived via AuditLogService::logAdmin()

Add AdminKioskService::restore(Kiosk $kiosk, int $adminUserId): void — $kiosk->restore(), leave status Disabled (an admin must deliberately re-enable and re-enroll), audit-log admin.kiosk.restored.
Rewrite KioskController::destroy() to call archive() unconditionally — drop the history check entirely. Add KioskController::restore() and a route:
phpRoute::post('kiosks/{kiosk}/restore', [KioskController::class, 'restore'])
    ->withTrashed()
    ->name('kiosks.restore');
Note ->withTrashed() on the route — required for route-model binding to resolve a soft-deleted kiosk.
UI: admin/kiosks/index.blade.php gets an "Archived" filter/tab (?archived=1 → Kiosk::onlyTrashed()). Archived rows show a Restore button instead of the usual actions. The delete button's confirm text should say "Archive" — staff need to understand history is retained, not destroyed.
Tests (tests/Feature/KioskArchiveTest.php)

Archiving a kiosk with reset history succeeds (regression guard — this is the reported bug)
Archived kiosk is excluded from the default index, appears under the archived filter
PasswordResetRequest with an archived kiosk still resolves ->kiosk->name and admin.requests.show renders 200
Archive nulls secret_hash; a heartbeat from that kiosk now returns 401 kiosk_not_enrolled
Restore brings it back as Disabled, not Active
Non-admin → 403


Feature 2 — Export registered students + roster diff
Context
Staff need to compare who has registered against the current SIS roster. The students table has no student_id column — the identifiers are email (unique), google_sub (unique), plus school, grade, org_unit_path, registered_at, reset_enabled. Join on email, and treat it case-insensitively (normalize with Str::lower(trim()) on both sides).
Build
2a. CSV export. Route — must be declared before students/{student} or Laravel binds the literal string "export" as a student:
phpRoute::get('students/export', [StudentController::class, 'export'])->name('students.export');
Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
StudentController::export(): StreamedResponse — use response()->streamDownload() with chunk(500), never load all students into memory. Columns:
email, name, school, grade, org_unit_path, registered_at, questions_count, has_registration_photo, reset_enabled, reset_requests_count, last_reset_at
Use withCount(['challengeQuestions', 'passwordResetRequests']). For has_registration_photo, use the existing Student::hasRegistrationPhoto() logic — but eager-load rather than N+1 (withExists or a whereHas count).
Audit-log admin.students.exported with the row count. This is a bulk PII extract and it belongs in the trail.
2b. Roster diff — this is the actual job to be done. A CSV that staff then have to VLOOKUP against another CSV is half a feature. Add:
phpRoute::get('students/roster-compare', [StudentController::class, 'showRosterCompare'])->name('students.roster-compare');
Route::post('students/roster-compare', [StudentController::class, 'rosterCompare'])->name('students.roster-compare.run');
Upload form accepts a SIS roster CSV. Validate: ['required', 'file', 'mimes:csv,txt', 'max:10240']. Require the uploaded file to have an email header column (case-insensitive match); if absent, return a validation error naming the headers you did find — staff will upload whatever their SIS exports and a vague error wastes everyone's afternoon.
Create app/Services/RosterComparisonService.php returning three buckets:

In roster, not registered — students who still need to register (the actionable list)
Registered, not in roster — likely withdrawn/transferred; candidates for reset_enabled = false
Both — count only

Parse with a streaming fgetcsv loop, not file(). Render the result in the browser with a "Download as CSV" action for each bucket. Do not persist the uploaded roster to disk — parse in-memory and discard. It is a student PII file with no lifecycle policy attached; the safest place for it is nowhere. Audit-log admin.students.roster_compared with bucket counts (counts only, never names or emails).
Tests (tests/Feature/StudentExportTest.php)

Export returns CSV with the expected header row and one row per student
students/export resolves to the export action, not a 404 model-bind failure (route-ordering regression guard)
Roster diff correctly buckets a fixture roster, including case-mismatched emails
Uploading a CSV without an email column returns a clear validation error
Non-admin → 403 on both


Feature 3 — Print password to a Dymo label
The security question — read this before writing code
Right now a reset password never leaves the encrypted boundary in durable form. PendingPasswordService stores it encrypted, canDisplayOnce() gates it to a single view, markDisplayed() stamps it, and kiosk/reset/pending-password.blade.php blanks it to ******** after $displaySeconds and redirects away. That is a tight, deliberate lifecycle.
A printed label breaks it. It creates a plaintext credential on a physical object that can be dropped in a hallway, photographed, or picked up by another student. This is not a reason to refuse the feature — a kid who can't write fast enough is a real problem, and staff asked for this — but it is a reason to build it with the same care as the rest of the system rather than bolting a window.print() onto the view.
Non-negotiable controls

Feature-flagged off by default. config/student-password-reset.php → 'label_printing_enabled' => filter_var(env('LABEL_PRINTING_ENABLED', false), FILTER_VALIDATE_BOOL). Ship dark; enable per-site.
Force change at next login, unconditionally, for any printed password. ResetGooglePasswordJob::forceChangeAtNextLogin() already short-circuits on office_verified_at !== null — add the same short-circuit for a new pending_password_printed_at column. Same reasoning: the credential left the boundary, so it rotates on first use. Non-negotiable.
Audit every print. New audit event password.label_printed with request id, kiosk id, student id — never the password. Migration adds pending_password_printed_at (nullable timestamp) to password_reset_requests.
Print is opt-in, on-screen display stays the default. The student clicks "Print label"; it is not automatic.
Print once. Gate the button on pending_password_printed_at === null and record the timestamp via a POST before/alongside printing. A student who can re-print at will can paper the building.

Build
Approach: browser print with a @media print stylesheet. Do not use the Dymo Connect JS SDK — it requires a local service and a localhost websocket on every kiosk, which is fleet-maintenance debt you don't need. The Dymo appears as an ordinary system printer; Chrome kiosk mode launched with --kiosk-printing bypasses the print dialog entirely, so one click ejects the label.
Add a POST route (session + CSRF, inside the existing ['web', EnsureKioskWebSession::class, EnsureResetPasswordModeConfigured::class] group in routes/kiosk.php):
phpRoute::post('/pending-password/{resetRequest}/print', [KioskResetController::class, 'markPrinted'])
    ->name('kiosk.reset.print');
markPrinted() — abort 403 unless config('student-password-reset.label_printing_enabled'); abort 409 if pending_password_printed_at !== null; verify the request belongs to the current kiosk session (mirror the existing ownership check in pendingPassword() — do not trust the route param alone, or any student could print any other student's password by guessing an id); stamp pending_password_printed_at = now(); audit-log; return JSON.
In pending-password.blade.php, gated on the config flag and pending_password_printed_at === null: a "Print label" button that fetch()es the POST, then calls window.print() on success.
Label markup sized for a Dymo 30252 address label (1⅛″ × 3½″):
html<style media="print">
  @page { size: 3.5in 1.125in; margin: 0.05in; }
  body * { visibility: hidden; }
  .print-label, .print-label * { visibility: visible; }
  .print-label { position: absolute; top: 0; left: 0; width: 3.4in; font-family: ui-monospace, monospace; }
  .print-label .pw { font-size: 18pt; font-weight: 700; letter-spacing: 0.06em; }
  .no-print { display: none !important; }
</style>

<div class="print-label" aria-hidden="true">
  <div style="font-size:9pt">{{ $studentName }}</div>
  <div class="pw">{{ $temporaryPassword }}</div>
  <div style="font-size:7pt">Change this when you sign in. Do not share.</div>
</div>
The existing countdown script blanks #temp-password and redirects after $displaySeconds — make sure the print button disappears at the same moment. A student must not be able to print after the display window closes.
Tests (tests/Feature/LabelPrintingTest.php)

Print route returns 403 when the feature flag is off
Successful print stamps pending_password_printed_at and writes a password.label_printed audit row containing no password material
Second print attempt → 409
A print POST for a reset request belonging to a different kiosk session → 403 (this is the important one — it's the IDOR that would let any student print any other student's password)
forceChangeAtNextLogin() returns true for a printed request even when config disables it for that pending-password type


Constraints

Do not modify the kiosk heartbeat/HMAC subsystem or OfficeVerificationService. Both are verified green.
Do not log, audit, or persist any plaintext password. The print audit event records that a print happened, never what was printed.
Do not persist uploaded roster CSVs to disk.
Preserve existing audit-log patterns (AuditLogService::logAdmin / logTech / logSystem) and the service-layer architecture — no business logic in controllers.
QUEUE_CONNECTION=database in production; phpunit.xml overrides to sync for tests. Nothing here dispatches jobs, but don't be surprised by the difference.

Deliverables

Migration: kiosks.deleted_at
Migration: password_reset_requests.pending_password_printed_at
Kiosk SoftDeletes + PasswordResetRequest::kiosk() withTrashed()
AdminKioskService::archive() / restore(), KioskController::destroy() / restore() + route, archived filter UI
StudentController::export() + route (declared before {student})
RosterComparisonService + upload form + result view + per-bucket CSV download
KioskResetController::markPrinted() + route + print stylesheet + gated button
ResetGooglePasswordJob::forceChangeAtNextLogin() short-circuit on pending_password_printed_at
tests/Feature/KioskArchiveTest.php, StudentExportTest.php, LabelPrintingTest.php
Config keys + .env.example entries (LABEL_PRINTING_ENABLED=false)
Directory Abstraction and Active Directory Password Reset
Context
sspkiosk is a Laravel 12 self-service student password-reset kiosk for Louisa County Public Schools. It is FERPA-scoped. Kiosks are ChromeOS kiosk-mode Chromebooks authorized through DHCP-reserved IP addresses.
Every student has:

A Google Workspace account
An Active Directory account

Not every student regularly uses Active Directory. Only certain classes require access to domain-joined Windows workstations. However, an AD account exists for every student.
The Active Directory sAMAccountName is derived from the local part of the student's Google email address:
textjdoe@lcps.k12.va.us → jdoe
Today, a reset updates only Google Workspace. A student in a Windows-based class can therefore complete the kiosk reset but remain locked out of the workstation.
There is no AD membership model and no need for one.
Do not add:

An ad_enabled student flag
A separate enrollment process
A student import for AD eligibility
Per-student directory targeting

Always include both Google Workspace and Active Directory in the directory plan. A student who never logs into Windows simply has an unused AD account whose password remains consistent with Google.

Prerequisite before starting
Obtain a non-production Active Directory test account and a domain controller you can bind against before beginning Part 5.
unicodePwd encoding and pwdLastSet semantics are exactly where an untested assumption becomes a production outage. The instruction to "verify against a non-production account" is a prerequisite, not a follow-up task. If that account does not exist, stop and get one.

Password-mode behavior
The application already supports:
phpResetPasswordMode::StudentSelectedPendingApproval
In this mode:

The student selects a password at the kiosk.
PendingPasswordService encrypts and stores it.
A technician approves the request in Slack.
The reset worker decrypts the password.
The same plaintext password is written to Google Workspace and Active Directory.
Neither directory forces another password change.

This is the intended production posture because it gives the student one durable password for both Google and Windows.
Both existing password modes must continue to work.
StudentSelectedPendingApproval
textSame student-selected password written to Google and AD.
No forced change in either directory.
Student leaves with one durable password.
TemporaryGenerated
textSame generated temporary password written to Google and AD.
Forced change enabled in both directories.
Passwords may diverge after the student changes them independently.
This mode unblocks access but does not guarantee one-password consistency.
Assume the Active Directory password policy has been aligned as closely as possible with:
phpconfig('student-password-reset.password_policy')
Do not build cross-directory policy reconciliation.
Active Directory password history, minimum-age rules, custom password filters, and similar AD-only rules may still reject a password that Google accepts. Those failures must be handled explicitly and visibly.

Part 1: Directory abstraction
Create:
php// app/Contracts/DirectoryPasswordResetter.php

namespace App\Contracts;

use App\Models\Student;

interface DirectoryPasswordResetter
{
    public function key(): string;

    public function isConfigured(): bool;

    public function supports(Student $student): bool;

    public function resetPassword(
        Student $student,
        string $password,
        bool $changePasswordAtNextLogin
    ): void;
}
Directory keys:
textgoogle
active_directory
Update GoogleWorkspaceDirectoryService to implement this interface.
Required behavior:
phppublic function key(): string
{
    return 'google';
}

public function supports(Student $student): bool
{
    return true;
}
ActiveDirectoryService::supports() must also return true.

Do not inject directory services into the queued job constructor
Queued job properties are serialized. Service objects, container bindings, LDAP connections, and tagged iterables must never become serialized job properties.
The job carries only the password-reset request ID.
phpuse Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ResetDirectoryPasswordsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $passwordResetRequestId
    ) {
    }

    public function handle(
        DirectoryPasswordResetCoordinator $coordinator
    ): void {
        $outcome = $coordinator->process(
            $this->passwordResetRequestId
        );

        if (
            $outcome->hasRetryableFailures()
            && $this->attempts() < $this->tries
        ) {
            $this->release(
                $this->backoffSeconds($this->attempts())
            );
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 30,
            2 => 120,
            default => 300,
        };
    }
}
Do not pass the underlying queue job into the coordinator.
The coordinator must return a small value object such as:
phpfinal readonly class DirectoryResetOutcome
{
    public function __construct(
        public bool $retryableFailuresRemain,
    ) {
    }

    public function hasRetryableFailures(): bool
    {
        return $this->retryableFailuresRemain;
    }
}
All directory orchestration belongs in:
textapp/Services/DirectoryPasswordResetCoordinator.php
The coordinator receives the ordered directory resetters through container injection.
Required execution order:

Google Workspace
Active Directory

Register the ordered collection in AppServiceProvider and inject it into the coordinator, not into the queued job.

Part 2: Typed failures and retry classification
A caught exception does not activate Laravel's $tries behavior. If a job catches an exception and returns normally, Laravel considers that execution successful.
Therefore, retry behavior must be explicit.
Create:
php// app/Exceptions/DirectoryResetException.php

namespace App\Exceptions;

class DirectoryResetException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly DirectoryRetryMode $retryMode = DirectoryRetryMode::None,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
Create:
textActiveDirectoryException extends DirectoryResetException
GoogleWorkspaceException extends DirectoryResetException
Keep GoogleWorkspaceException as a distinct subclass so existing catches and tests remain compatible.
The coordinator must never infer retryability by parsing exception messages.
Retry mode — three states, not a boolean
A single retryable boolean cannot express permission_denied, which must never auto-retry but is manually retryable once an administrator fixes delegation. Use an enum:
php// app/Enums/DirectoryRetryMode.php

enum DirectoryRetryMode: string
{
    case None = 'none';              // same password will never work
    case Manual = 'manual';          // admin may retry after fixing something
    case Automatic = 'automatic';    // job should release and retry itself
}
Automatic implies Manual is also available. None means neither.
Required reasons
ReasonRetry modeconnection_failedAutomatictimeoutAutomaticdc_unavailableAutomaticrate_limitedAutomaticpermission_deniedManualpolicy_rejectedNonenot_foundNoneinvalid_usernameNoneambiguous_matchNoneconfiguration_errorNoneunexpected_errorNone
Unknown exceptions must be sanitized and stored as:
textunexpected_error
Do not store raw exception messages from LDAP or Google unless they have been explicitly sanitized.
After all current directory results have been committed, the coordinator returns whether any directory failed with DirectoryRetryMode::Automatic.
The job then calls release() with backoff.
Manual and None failures must not automatically retry.

Part 3: Directory execution state
Add a nullable JSON column to password_reset_requests:
textdirectory_results
Use this structure:
json{
  "planned_directories": [
    "google",
    "active_directory"
  ],
  "required_directories": [
    "google",
    "active_directory"
  ],
  "results": {
    "google": {
      "status": "success",
      "reason": null,
      "retry_mode": "none",
      "attempts": 1,
      "last_attempt_at": "2026-07-14T13:02:11Z",
      "processing_started_at": null,
      "completed_at": "2026-07-14T13:02:12Z"
    },
    "active_directory": {
      "status": "failed",
      "reason": "policy_rejected",
      "retry_mode": "none",
      "attempts": 1,
      "last_attempt_at": "2026-07-14T13:02:13Z",
      "processing_started_at": null,
      "completed_at": null
    }
  }
}
Why there are two directory lists
planned_directories contains every registered directory resetter known when processing begins.
Normally:
json["google", "active_directory"]
required_directories contains the configured and supported directories that must succeed for that request.
This resolves the distinction between:

AD being intentionally disabled
AD being unsupported
AD being misconfigured
AD accidentally disappearing from the resolver
AD being required and failing

Both lists are written once on first processing and never recomputed for that request.
A configuration change between attempts must not silently add or remove a directory from an in-flight request.
Directory statuses
Supported statuses:
textpending
processing
success
failed
skipped
A skipped result must include one of:
textdisabled
not_configured
unsupported
Example when AD is disabled:
json{
  "planned_directories": [
    "google",
    "active_directory"
  ],
  "required_directories": [
    "google"
  ],
  "results": {
    "google": {
      "status": "success"
    },
    "active_directory": {
      "status": "skipped",
      "reason": "disabled"
    }
  }
}
This request is Completed because every required directory succeeded.

Overall request statuses
Add:
phpPasswordResetRequestStatus::PartiallyCompleted
Status calculation must use required_directories, not every planned directory.
Completed
Every required directory has:
textstatus = success
Failed
No required directory succeeded, and every required directory has reached a terminal failure with retry_mode of none or manual.
PartiallyCompleted
At least one required directory succeeded and at least one required directory has not succeeded.
This includes:

Terminal failure in one directory
Retryable failure awaiting another attempt
A processing state after another directory already succeeded

ApprovedProcessing
The request is still eligible for directory execution and no directory has yet produced a final mixed result.
Do not mark a request Failed when every directory failed with retry_mode = automatic and queue attempts remain. A DC blip that fails both Google and AD on attempt 1 must remain ApprovedProcessing so the release can retry. Only exhaust to Failed when no automatic retries remain.
Add:
css.badge-partially_completed
to:
textresources/views/layouts/admin.blade.php
Keep these legacy columns populated for backward compatibility:
textgoogle_reset_attempted_at
google_reset_success
google_error_message
Derive them exclusively from:
textdirectory_results.results.google
Do not maintain a separate competing source of truth.

Part 4: Do not hold database transactions during external calls
Do not retain the current pattern of holding lockForUpdate() while making Google or LDAP requests.
External directory calls may take seconds. A domain controller can also hang until AD_TIMEOUT.
The coordinator must use short transactions.
Per-directory processing flow
Step 1: claim a directory
In a short transaction:

Lock the request row with lockForUpdate().
Verify the request is eligible.
Verify the pending password has not expired.
Initialize the directory snapshot if it does not exist.
Reclaim stale processing entries if needed.
Select the next required directory whose status is pending or failed with retry_mode = automatic.
Never select a directory whose status is success.
Mark the selected directory processing.
Set processing_started_at.
Commit.

Do not decrypt the password while holding the row lock unless the existing service requires it. Prefer decrypting immediately after the transaction commits.
Step 2: execute outside the transaction
Outside any transaction:

Load the student relation if necessary.
Decrypt the pending password into memory.
Call the selected directory resetter.
Do not log or serialize the plaintext.

Step 3: record the result
In a second short transaction:

Lock the request.
Verify the directory is still marked processing.
Increment its attempt count.
Store either success or sanitized failed with its retry_mode.
Clear processing_started_at.
Recalculate request status.
Recalculate retry eligibility.
Commit.

Then continue to the next eligible directory.

Stale-processing recovery
If a worker dies after marking a directory processing, that request must not remain stranded forever.
Add:
phpDIRECTORY_STALE_PROCESSING_MINUTES=5
Place this in directory-processing configuration, not the AD-specific file — it applies to Google as well.
On coordinator entry, reclaim a directory when:
textstatus = processing
processing_started_at is older than the configured threshold
Reclaim by changing it to:
textpending
Audit:
textdirectory.processing.reclaimed
Include:

Request ID
Directory key
Previous processing timestamp

Do not include any password data.

Concurrency requirements
Two workers must never call the same directory for the same request at the same time.
The lockForUpdate() claim transaction and the durable processing marker are the concurrency guard.
A worker encountering a non-stale processing entry must not execute that directory.
The worker may:

Process another eligible directory, if safe
Exit and allow the active worker to finish
Release briefly when the request still contains retryable work

Do not rely only on in-memory locks.

Part 5: ActiveDirectoryService
Use:
textdirectorytree/ldaprecord
Use direct LDAPS.
Do not use:

WinRM
PowerShell remoting
Plain LDAP
Automatic downgrade
StartTLS fallback


Username derivation
phpprivate function samAccountName(Student $student): string
{
    $local = Str::before($student->email, '@');

    if (
        $local === ''
        || strlen($local) > 20
        || ! preg_match('/^[A-Za-z0-9._-]+$/', $local)
    ) {
        throw new ActiveDirectoryException(
            'Cannot derive Active Directory username.',
            'invalid_username',
            DirectoryRetryMode::None,
        );
    }

    return $local;
}
A local part longer than 20 characters must produce invalid_username.
Do not allow the LDAP library to return a cryptic low-level error for this case.

Active Directory search
Search only within:
textAD_STUDENT_OU
Never search the entire directory tree.
Use LdapRecord's query builder. Do not interpolate a raw LDAP filter string.
The query must constrain the object to an AD user and the derived sAMAccountName.
Conceptually:
text(&(objectCategory=person)(objectClass=user)(sAMAccountName=jdoe))
Required behavior:

Zero matches → not_found
More than one match → ambiguous_match
Exactly one match → continue

Never guess and never use the first result from an ambiguous query.

Password update
Prefer LdapRecord's documented password-reset API, such as the supported setPassword() behavior for the installed library version.
Do not manually encode unicodePwd unless the library method has been tested and shown not to work correctly with the district's domain controllers.
When manual encoding is genuinely required:
phpiconv(
    'UTF-8',
    'UTF-16LE',
    '"' . $password . '"'
);
The password must be enclosed in double quotes before UTF-16LE encoding.
Set:
php$user->update([
    'pwdlastset' => $changePasswordAtNextLogin ? 0 : -1,
]);
Verify the exact LdapRecord behavior against the non-production AD test account before production rollout. Do not assume password-reset and pwdLastSet behavior without testing it against the actual domain.

LDAPS certificate validation
LDAPS must authenticate the server certificate, not merely encrypt traffic.
Production requirements:

Trusted domain-controller certificate chain
Hostname validation
No TLS_REQCERT never
No self-signed-certificate bypass
No plaintext LDAP fallback
No silent downgrade

If the DC certificate chain cannot be validated, configuration health must fail loudly.
Document installation of the district CA chain in:
textdocs/
Do not store certificate bypass instructions as a supported production configuration.

Active Directory configuration
Create:
php// config/active-directory.php

return [
    'enabled' => filter_var(
        env('AD_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'hosts' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', (string) env('AD_HOSTS', ''))
        )
    )),

    'port' => (int) env('AD_PORT', 636),

    'base_dn' => env('AD_BASE_DN'),

    'student_ou' => env('AD_STUDENT_OU'),

    'username' => env('AD_USERNAME'),

    'password' => env('AD_PASSWORD'),

    'use_ssl' => true,

    'timeout' => (int) env('AD_TIMEOUT', 10),
];
Ship:
envAD_ENABLED=false
isConfigured() must return false when:

AD is disabled
Hosts are missing
Base DN is missing
Student OU is missing
Username is missing
Password is missing
Port is not 636
SSL is not enabled
Required certificate trust cannot be established

Differentiate:
textdisabled
not_configured
configuration_error
where practical.
Use the library's host failover strategy for multiple AD_HOSTS.
Do not log:

Bind passwords
Directory credentials
Raw LDAP diagnostic payloads
Full connection strings containing secrets


Active Directory service-account scope
The service account must receive only the permissions needed to reset passwords in the student OUs.
Document:

Read access to the student OU
Reset Password delegation on student accounts
Ability to write pwdLastSet when needed
No domain-admin membership
No broader OU delegation
No workstation or server administrative privileges


Active Directory policy rejection
Active Directory constraint violations do not always distinguish among:

Password history
Complexity
Minimum password age
Custom password filters
Other domain policy rules

Use:
textreason = policy_rejected
retry_mode = none
An optional sanitized subreason may be:
textpassword_history_or_policy
Do not tell staff that history was definitely the cause unless AD provides a reliable, documented diagnostic.
Staff-facing and student-facing copy:
textActive Directory rejected the selected password. It may have been used previously, or it may not meet the domain password policy. Please choose a different password.
This message must reach the kiosk recovery workflow, not only the admin page.

Active Directory health command
Create:
textphp artisan ssp:ad-check
The command must:

Report whether AD is enabled.
Validate required configuration.
Confirm port 636 is being used.
Attempt an LDAPS bind.
Verify certificate trust.
Confirm the service account can read AD_STUDENT_OU.
Optionally resolve a supplied sample sAMAccountName.
Report sanitized health results.
Never display credentials or password values.

Example usage:
bashphp artisan ssp:ad-check
php artisan ssp:ad-check --sam=jdoe
Audit-log every execution as admin.ad_check.executed, including whether --sam was supplied and the resolved result status. This command performs a directory lookup against student PII from a shell; the audit trail must record that it happened.
Surface AD connectivity health on the admin dashboard alongside queue health.
A domain-controller outage affects every student reset and must be visible before teachers begin reporting failures.

Part 6: Persist credential semantics per request
Do not determine credential behavior from global configuration when the worker executes.
Configuration can change after a student creates a request but before the technician approves it.
Add columns to password_reset_requests:
textpassword_mode
password_origin
force_change_at_next_login
superseded_student_selected_password
retry_available
password_mode
Persist the ResetPasswordMode value active when the credential was created.
Examples:
textstudent_selected_pending_approval
temporary_generated
password_origin
Supported values:
textstudent_selected
temporary_generated
office_generated_temporary
force_change_at_next_login
Resolve and persist this once when the credential is created.
The coordinator reads this column.
It must not read the current global password-mode configuration to decide force-change behavior.
superseded_student_selected_password
Set to true when office verification discards a student-selected password and replaces it with an office-generated temporary password.
retry_available
This must reflect whether an existing encrypted password can still be meaningfully retried — that is, whether any required directory failed with retry_mode of automatic or manual and the pending password has not expired.
Backfill existing requests using the current configuration during migration, while documenting that the backfill is an approximation for historical rows.

Note for Prompt 2: these columns move to password_reset_revisions in the next build and become read-only projections here. Do not design anything that assumes they are permanently authoritative on the request row.


Office verification semantics
OfficeVerificationService::verify() currently creates a generated temporary password.
Keep that behavior.
When it does so, persist:
textpassword_origin = office_generated_temporary
force_change_at_next_login = true
When it replaces a student-selected password, also persist:
textsuperseded_student_selected_password = true
The admin UI must display this warning before confirmation:
textThis action will replace the password selected by the student with a new temporary password. The student will be required to change it at the next login in both Google Workspace and Windows.
Office-generated temporary passwords may be shown once to the authorized staff member responsible for giving the credential to the student.
Student-selected passwords must never be shown to staff.

Part 7: Student-selected plaintext must never surface
In student-selected mode, the password is the student's intended durable credential.
It must never appear in:

Slack
Admin HTML
Audit rows
Application logs
Queue payloads
Exception messages
Error context
Debug output
Notifications
Browser responses after initial student submission

Only the directory execution worker may decrypt it, and only while performing directory operations.
The approving technician cannot view or recover the student-selected password.
Document this limitation explicitly.
A student who mistypes or forgets the selected password must use a new recovery flow. Prompt 2 defines that workflow.
Office-generated temporary credentials are the only passwords that may surface once to authorized staff.

Label printing
Label printing is prohibited for requests whose persisted password_mode is:
textstudent_selected_pending_approval
Do not base this check only on current global configuration.
Required behavior:
phpmarkPrinted()
must return HTTP 403 when the request's persisted mode is student-selected, even if:
envLABEL_PRINTING_ENABLED=true
The print button must not render for those requests.
Add a nonfatal warning to ConfigurationValidatorService when:
envLABEL_PRINTING_ENABLED=true
RESET_PASSWORD_MODE=student_selected_pending_approval
Warning text:
textPassword-label printing is disabled for student-selected password requests because the password is a durable credential rather than a temporary password.
Keep the existing printed-password force-change behavior for temporary-generated and office-generated passwords.

Part 8: Pending-password lifecycle
Remove PendingPasswordService::deleteOnGoogleFailure().
That method destroys the encrypted plaintext when Google fails. Under a two-directory model that plaintext is still needed to retry AD after a Google success, and to retry Google after a transient failure. Replace it with a lifecycle-aware method that deletes only under the conditions below.
Delete the encrypted pending password only when:

Every required directory succeeds
The request is denied
The request is abandoned
The request expires
An administrator explicitly cancels it
A replacement-password revision supersedes it

Retain the encrypted pending password when:

One or more required directories remain retryable (automatic or manual)
The request is PartiallyCompleted
A manual retry remains available
A transient directory retry is pending

Respect:
textpending_password_expires_at
When it expires:

Do not attempt another directory reset
Set retry_available = false
Delete the encrypted password
Mark the request appropriately
Preserve the directory split state for staff reporting

Separate overall status from retry eligibility.
Example:
textstatus = Failed
retry_available = false
The request may still need manual reconciliation even though no automated retry remains possible.

Required tests
Create:
texttests/Feature/DirectoryPasswordResetTest.php
Use fake directory resetters.
Never connect to a real domain controller in automated tests.
Test all of the following:

Both configured directories are attempted for every student.
The exact same plaintext password is passed to both resetters.
jdoe@lcps.k12.va.us derives to jdoe.
An email local part longer than 20 characters produces invalid_username.
Illegal username characters produce invalid_username.
Invalid usernames cause no LDAP query or password call.
Persisted request semantics control force-change behavior even when global configuration changes after request creation.
Student-selected mode passes false for force-change to both directories.
Temporary-generated mode passes true to both directories.
Google succeeds and AD returns connection_failed:

Google is called once.
AD is called again after explicit job release.
AD succeeds on the second attempt.
Google is never rewritten.


Google succeeds and AD returns policy_rejected:

Request becomes PartiallyCompleted.
The job is not released.
results.google.status = success.
results.active_directory.status = failed, reason = policy_rejected, retry_mode = none.
The encrypted pending password is retained, not deleted.
retry_available reflects that no directory is retryable with the current password.


Both directories fail with connection_failed on attempt 1:

Request remains ApprovedProcessing, not Failed.
The job releases.
Failed is only reached once automatic attempts are exhausted.


AD returns permission_denied:

retry_mode = manual.
The job does not auto-release.
retry_available = true.


Retry after full success performs no directory calls.
AD_ENABLED=false:

AD is recorded as skipped.
Reason is disabled.
AD is not in required_directories.
Google success produces Completed.


planned_directories and required_directories remain stable across retries even if configuration changes.
A non-stale processing directory cannot be claimed by a second worker.
A processing entry older than the configured threshold is reclaimed to pending.
Two workers processing the same request produce exactly one successful call per directory.
Legacy Google status columns are derived correctly from the Google result.
markPrinted() returns 403 for a persisted student-selected request even when label printing is globally enabled.
The print button is absent for student-selected requests.
Student-selected plaintext never appears in:

Audit rows
Log messages
Slack payloads
Admin responses
Exception messages
Serialized job payloads


Office-generated temporary passwords preserve their existing one-time-display behavior.


Constraints
Do not inject resetters, LDAP services, Google services, or other service objects into the queued job constructor.
Do not rely on $tries to retry caught exceptions.
Use explicit release() only after durable directory state has been committed.
Do not pass the queue job object into the coordinator.
Do not hold a database transaction open during a Google or LDAP call.
Do not reattempt a directory whose recorded status is success.
Do not delete the pending password after partial completion.
Do not determine force-change behavior from current global configuration.
Use the request's persisted credential semantics.
Do not expose student-selected plaintext anywhere after submission.
Do not allow label printing for student-selected requests.
Do not add a per-student AD membership flag, import, or enrollment process.
Use LDAPS on port 636 with full certificate validation.
Do not use TLS_REQCERT never.
Do not allow self-signed bypasses in production.
Do not fall back to plain LDAP.
Ship:
envAD_ENABLED=false
Do not modify:

Kiosk heartbeat behavior
IP authorization
KioskNetworkService
EnsureKioskWebSession
The 419 self-heal
Kiosk archive or soft-delete behavior
Roster export

Those areas are already verified.

Deliverables
Create:
textapp/Contracts/DirectoryPasswordResetter.php
app/Enums/DirectoryRetryMode.php
app/Exceptions/DirectoryResetException.php
app/Exceptions/ActiveDirectoryException.php
app/Exceptions/GoogleWorkspaceException.php
app/Services/ActiveDirectoryService.php
app/Services/DirectoryPasswordResetCoordinator.php
app/Jobs/ResetDirectoryPasswordsJob.php
config/active-directory.php
Update:
textGoogleWorkspaceDirectoryService
AppServiceProvider
PendingPasswordService          (remove deleteOnGoogleFailure)
OfficeVerificationService
SlackApprovalService
ConfigurationValidatorService
PasswordResetRequest model
PasswordResetRequestStatus
Admin request views
Admin dashboard
Slack result messaging
Kiosk submitted and failure messaging
Add migrations for:
textdirectory_results
password_mode
password_origin
force_change_at_next_login
superseded_student_selected_password
retry_available
Add:
textphp artisan ssp:ad-check
Add:
texttests/Feature/DirectoryPasswordResetTest.php
Update:
text.env.example
docs/
Documentation must cover:

LDAPS setup
District CA trust
Domain-controller certificate validation
Student-OU scoping
Service-account delegation
AD health checks
The difference between the two reset modes
Why student-selected passwords never surface to staff
Partial-completion behavior
Recovery limitations deferred to Prompt 2


Build order
Complete Parts 1 through 4 first.
Get the full existing test suite green before implementing Active Directory or changing UI surfaces.
The job rename has a wide blast radius.
Search for all references to:
textResetGooglePasswordJob
Known affected areas include:
textOfficeVerificationTest
SlackInteractionTest
PendingPasswordFlowTest
OfficeVerificationService
SlackApprovalService
Update them to use:
textResetDirectoryPasswordsJob
and the new directory-result model.
Do not make old tests green by restoring the single-directory guard.
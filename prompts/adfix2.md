Partial-Failure Recovery and Password Revisions
Build this only after Prompt 1 is merged and the complete test suite is green.

This prompt migrates Prompt 1's credential model
Read this section before writing any code.
Prompt 1 placed the following on password_reset_requests:
textpassword_mode
password_origin
force_change_at_next_login
retry_available
directory_results
encrypted pending password (via PendingPasswordService)
This build moves the authoritative copy of all of them onto password_reset_revisions.
After this build:

password_reset_revisions is the sole authority for credential semantics and directory execution state.
The coordinator reads the active revision, never the request row.
PendingPasswordService operates on a revision, not a request.
Any columns retained on password_reset_requests are denormalized, read-only projections of the active revision, updated from it for display efficiency — never written independently.

Do not implement this as an additive table that runs alongside the request columns. Two sources of truth for credential semantics is the exact failure mode Prompt 1 forbids, and the drift will be invisible until a replacement revision reads a stale force_change_at_next_login off the request row.
Migration must backfill every existing request into a revision 1, carrying over its current password_mode, password_origin, force_change_at_next_login, directory_results, retry_available, encrypted pending password, and pending_password_expires_at.

Problem being solved
A student selects a password.
Google accepts it.
Active Directory rejects it because of:

Password history
Complexity
Minimum password age
A custom password filter
Another domain policy rule

Per-directory idempotency correctly prevents Google from being automatically rewritten.
However, retrying AD with the same rejected password will fail again.
The student needs to select another password, and that replacement password must deliberately be written to both Google and AD to restore one-password consistency.
Without a revision workflow, this becomes a manual domain-controller repair, which defeats the purpose of the system.

Two distinct recovery actions
Do not combine these actions.
Action 1: Retry failed directories
This action:

Uses the existing encrypted password on the active revision
Attempts only directories that have not succeeded
Never rewrites a successful directory
Is safe and idempotent
Is available only before the pending password expires
Is appropriate for failures that may succeed without changing the password

Available when the failure's retry_mode is automatic or manual:
textconnection_failed
timeout
dc_unavailable
rate_limited
permission_denied      (manual only — after an administrator fixes delegation)
Do not offer retry when the failure's retry_mode is none:
textpolicy_rejected
invalid_username
not_found
ambiguous_match
configuration_error
unexpected_error
The same password will not fix those failures.
Action 2: Replace password in all directories
This action:

Requires a newly selected password
Creates a new password revision
Deliberately resets all required directories to pending
Includes Google even if Google succeeded in the prior revision
Requires explicit approval
Is never an automatic retry
Is the recovery path for policy_rejected

The UI must make clear that this action intentionally changes a working Google password.

Revision model
Create:
textpassword_reset_revisions
Each revision must persist:
textid
password_reset_request_id
revision_number
password_mode
password_origin
force_change_at_next_login
encrypted_pending_password
pending_password_expires_at
pending_password_created_at
pending_password_displayed_at
pending_password_printed_at
directory_results
retry_available
status
superseded_at
created_at
updated_at
Add any required integrity fields or casts.
Revision rules

Revision numbers begin at 1.
Only one revision may be active at a time.
Creating a replacement revision supersedes the previous active revision.
Superseding a revision deletes its encrypted plaintext.
Previous directory results remain in revision history.
A new replacement revision starts every required directory as pending.
Google is included even when it succeeded in the previous revision.
Historical revisions are never mutated to look like the new revision succeeded.

Add a database or transactional guard that prevents two active revisions for the same request. A partial unique index on (password_reset_request_id) where superseded_at IS NULL is the cleanest enforcement.

Primary replacement workflow: student re-selection at the kiosk
The primary recovery path must preserve the invariant:
textStudent-selected plaintext never surfaces to staff.
When AD returns policy_rejected after Google has succeeded:

Mark the request PartiallyCompleted.
Do not offer retry with the same password.
Allow an authorized administrator to initiate password replacement.
Require typed confirmation.
Move the request into a re-selection state.
Tell the student that the original password may already work in Google but not on Windows.
Have the student return to the kiosk and select a different password.
Create revision 2.
Require approval again.
Write revision 2's password to both Google and AD.
On success, mark the request Completed.

Student-facing copy:
textThe password you selected was accepted by Google but could not be used for your school computer account. Your first password may still work in Google. Please choose a different password so the same password can be applied to both Google and Windows.
Do not reveal which specific password Google accepted.

Administrative fallback
Do not add a general admin field where staff type a durable student-selected password.
That would violate Prompt 1's plaintext-handling posture.
When the student cannot return to a kiosk, use the existing office-verification temporary-password process.
That fallback:

Creates an office-generated temporary revision
Writes the same temporary password to both directories
Forces change at next login in both directories
Displays the temporary credential once to authorized staff
Records that the student-selected revision was superseded

Do not describe this fallback as delivering one durable password. After forced changes, Google and AD may diverge.

Replacement confirmation
Replacing a password must require typed confirmation, not only a button click.
Confirmation text:
textREPLACE PASSWORD
Warning:
textGoogle already accepted the student's previous password. Replacing it will change the password in Google Workspace and Active Directory. The student's currently working Google password will stop working.
The action must require an explicit reason.
Audit:
textadmin.request.password_replacement_started
Include:

Request ID
Previous revision number
New revision number when created
Administrator ID
Sanitized replacement reason

Never include password plaintext.

Replacement rate limit
Limit replacement revisions per request.
Default:
text3 replacement revisions
Make the limit configurable.
When exceeded:

Block another replacement
Require manual reconciliation
Preserve the audit trail
Do not retain unnecessary plaintext
Display a clear administrative message

Do not allow an endless policy-rejection loop.

Retry action
The existing retry action must be updated to operate on the active revision.
It must:

Confirm retry_available = true on the active revision.
Confirm the encrypted password has not expired.
Confirm at least one failed directory has retry_mode of automatic or manual.
Dispatch ResetDirectoryPasswordsJob.
Attempt only non-success directories.
Never reset successful directories.

Do not show retry when every failure has retry_mode = none.
Show the replacement action instead.

Cancel and abandon
Add an admin action to cancel or abandon a stuck request.
The action must:

Require confirmation
Require a reason
Delete encrypted plaintext for the active revision
Set retry_available = false
Mark the active revision terminal
Mark the request terminal
Prevent further directory execution
Tell staff that the student must begin a new request

Audit:
textadmin.request.cancelled
Never include password plaintext.

Expiration behavior
Update:
textssp:expire-requests
It must handle PartiallyCompleted requests.
When the active revision's pending password expires:

Do not retry any directory.
Set retry_available = false.
Delete the encrypted password.
Mark the active revision Failed.
Mark the request Failed or another explicit terminal reconciliation status.
Preserve which directories succeeded and failed.
Surface the split-directory state in the failed-attempts report.

A request where Google succeeded and AD failed must not disappear into a generic expired list.
Staff need to know that manual reconciliation may be required.

Admin surfaces
The request detail page must show revision history.
For each revision, show:

Revision number
Password origin
Password mode
Force-change behavior
Created time
Superseded time
Directory results
Attempt counts
Failure reasons
Retry eligibility
Final status

Do not show password plaintext.
Visually distinguish:

Retry failed directories
Replace password in all directories
Cancel request

These actions have materially different consequences.

Slack behavior
When a partial failure occurs:

Update the approval message.
State which directory succeeded.
State which directory failed.
Use sanitized failure wording.
Do not imply that the student is fully fixed.
Do not include password plaintext.

For policy_rejected, indicate that the student must choose another password.
Do not provide a one-click Slack replacement action that bypasses the required typed confirmation and kiosk re-selection process.

Audit requirements
Add audit events for:
textdirectory.retry.requested
directory.retry.completed
directory.retry.failed
admin.request.password_replacement_started
student.password_reselection.submitted
password_revision.created
password_revision.superseded
password_revision.completed
admin.request.cancelled
password_revision.expired
Include:

Request ID
Revision number
Directory key when applicable
Acting user or kiosk
Sanitized reason
Timestamps

Never include:

Plaintext password
Encrypted password
Password hashes
LDAP credential data


Required tests

Every existing request is backfilled into a revision 1 with its credential semantics intact.
The coordinator reads the active revision, not the request row. (Set divergent values on the request row and assert the revision wins.)
AD policy_rejected causes the retry action to be hidden.
AD policy_rejected causes the replacement action to be shown.
AD permission_denied causes the retry action to be shown (manual retry).
Retry uses the existing active revision.
Retry attempts only failed, retryable directories.
Retry never rewrites a successful Google reset.
Replacement requires typed confirmation.
Replacement requires a reason.
Replacement creates revision 2.
Revision 2 starts all required directories as pending, including Google.
Revision 1 becomes superseded.
Revision 1 encrypted plaintext is deleted.
Revision 1 directory results remain visible in history.
Revision 2 student-selected plaintext never appears to staff.
Revision 2 success marks the request Completed.
Both revision histories remain visible after completion.
Replacement-rate limit is enforced.
Two active revisions for one request are impossible (assert the DB guard fires).
Office fallback creates an office-generated temporary revision.
Office fallback forces change in both directories.
Cancel deletes active plaintext and prevents further execution.
Expired PartiallyCompleted requests:

Become terminal.
Have retry_available = false.
Lose encrypted plaintext.
Remain visible as split-directory cases.


Simultaneous retry and replacement submissions result in exactly one accepted transition.
Simultaneous replacement submissions create only one new active revision.
No password value appears in audit, logs, Slack, admin HTML, or exception output.


Concurrency
Retry, replacement, cancellation, and expiration must use short row-lock transactions.
Examples of conflicting operations:

Retry and replace submitted simultaneously
Replace and cancel submitted simultaneously
Worker completion and replacement submitted simultaneously
Expiration command and retry submitted simultaneously

Exactly one valid state transition may win.
The losing operation must:

Exit safely
Display a clear conflict message
Perform no directory call
Create no duplicate revision
Preserve plaintext lifecycle rules


Constraints
Do not maintain credential semantics on both the request and the revision. The revision is authoritative; request columns are read-only projections.
Do not treat replacement as a retry.
Do not automatically overwrite a directory that already succeeded.
Do not allow a new password to be sent only to AD while leaving Google on the old revision.
Do not expose student-selected plaintext to administrators.
Do not build a generic admin durable-password-entry field.
Do not allow replacement without typed confirmation.
Do not allow endless replacement revisions.
Do not delete historical directory results.
Do not retain superseded plaintext.
Do not allow expired plaintext to remain retryable.
Do not change the kiosk IP-authorization or heartbeat implementation.

Deliverables
textpassword_reset_revisions migration + backfill of all existing requests
PasswordResetRevision model
Revision status enum
Revision-aware PendingPasswordService
Revision-aware DirectoryPasswordResetCoordinator
Retry-failed-directories action
Replace-password workflow
Kiosk password re-selection flow
Cancel or abandon action
Revision-history admin UI
Revision-aware Slack updates
Expiration and failed-attempt reporting updates
Add or update tests covering every required recovery and concurrency scenario.
Build this as a distinct feature set.
Do not compress it into a button added to the existing request page. The revision model, kiosk re-selection flow, and retry-versus-replace distinction are core correctness requirements.
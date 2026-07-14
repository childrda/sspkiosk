# Active Directory password reset (LDAPS)

sspkiosk writes the same approved password to **Google Workspace** and **Active Directory**. Chromebook kiosk IP/heartbeat behavior is unchanged.

## Modes

| Mode | Password | Force change |
|------|----------|--------------|
| `student_selected_pending_approval` | Student-chosen durable password | No (persisted at request creation) |
| `temporary_generated` | Generated temporary password | Yes |

Student-selected plaintext is never shown to staff, Slack, admin HTML, audit, or logs. Only the directory worker decrypts it. Office verification may replace it with an office-generated temporary password (`password_origin=office_generated_temporary`, `force_change_at_next_login=true`).

## Enable AD

```env
AD_ENABLED=true
AD_HOSTS=dc1.example.org,dc2.example.org
AD_PORT=636
AD_BASE_DN=DC=example,DC=org
AD_STUDENT_OU=OU=Students,DC=example,DC=org
AD_USERNAME=CN=sspkiosk,OU=Service Accounts,DC=example,DC=org
AD_PASSWORD=...
AD_TIMEOUT=10
DIRECTORY_STALE_PROCESSING_MINUTES=5
```

Ship with `AD_ENABLED=false`. Production requires LDAPS on port **636** with a trusted DC certificate chain and hostname validation. Do not use plain LDAP, StartTLS fallback, or `TLS_REQCERT never`.

Install the district CA chain on the app host so PHP OpenLDAP can validate the DC certificate. Verify against a **non-production** student account before rollout (`unicodePwd` / `pwdLastSet` behavior is DC-specific).

## Service account

Delegate only:

- Read access to `AD_STUDENT_OU`
- Reset Password on student accounts
- Ability to write `pwdLastSet` when forcing change

No domain-admin membership and no broader OU/server rights.

Username derivation: email local part → sAMAccountName (max 20 characters, `[A-Za-z0-9._-]+`). Searches are scoped to `AD_STUDENT_OU` only.

## Health check

```bash
php artisan ssp:ad-check
php artisan ssp:ad-check --sam=jdoe
```

Every run is audited as `admin.ad_check.executed`. The admin dashboard shows AD enabled/configured status alongside queue depth.

## Partial completion

If Google succeeds and AD returns `policy_rejected`, the request becomes `partially_completed`. The encrypted password is retained on the **active revision**. Automated retry is not offered (`retry_mode=none`).

Recovery is a **password replacement**, not a retry:

1. An administrator types `REPLACE PASSWORD` and a reason on the request detail page.
2. Revision 1 is superseded (ciphertext deleted; directory history kept).
3. Revision 2 starts all required directories as `pending`, including Google.
4. The student returns to the kiosk, chooses a different password, and Slack re-approval is required.
5. On success, both directories receive revision 2's password and the request becomes `Completed`.

Temporary office fallback creates an office-generated temporary revision with `force_change_at_next_login=true` for both directories. That is a temporary credential, not a durable shared password.

Same-password **directory retry** remains available for `connection_failed`, `timeout`, `dc_unavailable`, `rate_limited`, and (after staff fix) `permission_denied`.

Revision limit: `PASSWORD_MAX_REPLACEMENT_REVISIONS` (default 3). Beyond that, staff must reconcile manually.

Credential authority lives on `password_reset_revisions`. Columns on `password_reset_requests` are denormalized projections only.

## Job

`ResetDirectoryPasswordsJob` orchestrates directories through `DirectoryPasswordResetCoordinator`. Successful directories are never rewritten. Automatic failures (`connection_failed`, `timeout`, …) release the job with backoff; `permission_denied` is manual-only.

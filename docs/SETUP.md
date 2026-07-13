# SSP Kiosk — Setup Guide

Secure K-12 student password reset request system (Laravel). Students register while they can still sign in with Google; later they use a district kiosk to request a reset that **must** be approved in Slack before Google Workspace resets the password.

## Requirements

- PHP 8.2+
- MySQL (recommended for production) or SQLite (local dev)
- Composer
- Queue worker (`php artisan queue:work` or `composer run dev`)
- Node/npm (optional, for Vite assets)

## Initial setup

1. Copy environment file and generate app key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

2. Configure database in `.env` (MySQL example):

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sspkiosk
   DB_USERNAME=root
   DB_PASSWORD=
   ```

3. Run migrations:

   ```bash
   php artisan migrate
   ```

4. Copy SSP Kiosk variables from `.env.example` into `.env` and fill in values for your district (see sections below). **Never commit real secrets.**

5. Store Google service account JSON outside the web root, e.g. `storage/app/google/service-account.json`, and set:

   ```env
   GOOGLE_SERVICE_ACCOUNT_JSON_PATH=storage/app/google/service-account.json
   ```

6. Student photos are stored on the `local` disk (`storage/app/private`) by default. Do not expose this path via the public web root.

7. Start the app and queue worker:

   ```bash
   php artisan serve
   php artisan queue:work
   ```

## Configuration groups

All tunable values live in `.env` and are read through Laravel config files only:

| Config file | Purpose |
|-------------|---------|
| `config/google-workspace.php` | Student domain, OAuth, Admin SDK |
| `config/slack.php` | Bot token, signing secret, channel, approvers |
| `config/kiosk.php` | Networks, HMAC, heartbeat |
| `config/student-password-reset.php` | Policy, challenge questions, temp passwords |
| `config/audit.php` | Audit log retention |

Use `config('...')` in application code — not `env()` outside config files.

## Slack approval (Phase 6)

1. Create a Slack app with **Bot Token Scopes**: `chat:write`, `files:write`, `usergroups:read`.
2. Enable **Interactivity** with Request URL: `https://your-host/slack/interactions`
3. Set in `.env`: `SLACK_BOT_TOKEN`, `SLACK_SIGNING_SECRET`, `SLACK_RESET_CHANNEL_ID`, `SLACK_APPROVER_USERGROUP_ID`
4. Invite the bot to the reset channel.

When a pending reset is created, `SendSlackResetApprovalJob` posts a Block Kit message with student/kiosk details, **inline photos** (kiosk reset photo and registration photo on file), challenge results, risk flags, and **Approve / Deny / Needs Office Verification** buttons.

Photos appear as **image blocks** inside the approval message when `APP_URL` uses **HTTPS** and Slack can reach your server. Signed links expire after `SLACK_PHOTO_URL_TTL_MINUTES` (default 15). If `APP_URL` is not public HTTPS (for example local dev), photos are uploaded as **thread replies** under the message instead.

Approving dispatches `ResetGooglePasswordJob`. Passwords are **never** sent to Slack.

## Reset password modes

Set `RESET_PASSWORD_MODE` in `.env` (also validated by `php artisan ssp:config-check` and kiosk middleware — invalid values block reset requests):

| Mode | Behavior |
|------|----------|
| `temporary_generated` (default) | After correct challenge answers, the system generates a temporary password, encrypts it in `password_reset_requests.encrypted_pending_password`, shows it **once** on the kiosk (`/kiosk/reset/pending-password/{id}`), then queues Slack approval. The student may leave; the password is inactive until approved. |
| `student_selected_pending_approval` | After challenges, the student enters/confirms a new password on `/kiosk/reset/password`. It is validated, encrypted, and stored pending. A confirmation screen is shown (`/kiosk/reset/submitted/{id}`); Slack approval follows. |

Related `.env` keys (see `.env.example`): `GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_*`, `PENDING_PASSWORD_*`, `DELETE_PENDING_*`, `PASSWORD_*` policy settings.

## Google password reset (Phase 7+)

After Slack approval, `ResetGooglePasswordJob`:

1. Loads the request by ID only (no password in the queue payload).
2. Decrypts `encrypted_pending_password` inside the job.
3. Calls the Directory API `users.update` with the pending password.
4. Sets `changePasswordAtNextLogin` from config (`GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_TEMPORARY` or `_STUDENT_SELECTED`).
5. Deletes `encrypted_pending_password` per `DELETE_PENDING_PASSWORD_*` settings.
6. Marks the request `completed` and updates the Slack thread (no password in Slack).

Requires `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`, domain-wide delegation, and `GOOGLE_ADMIN_IMPERSONATION_EMAIL`.

Ensure the queue worker is running so this job processes after approval.

## Pending password display

Students do **not** wait at the kiosk for Slack approval.

- **temporary_generated:** `/kiosk/reset/pending-password/{id}` shows the password once for `PENDING_PASSWORD_DISPLAY_SECONDS`, then clears. Message explains the password will not work until staff approve.
- **student_selected_pending_approval:** `/kiosk/reset/submitted/{id}` confirms submission only (password is not shown again).

Pending passwords are encrypted at rest, never logged, never in Slack/admin/audit, and removed after approval, denial, expiration, or Google failure (per config).

## Registering a kiosk

Kiosk-mode **Chromebooks** are the supported default. Each device needs a unique **DHCP reservation** stored as the kiosk `allowed_ip`. Authorization uses that reserved IP on your managed network plus an enrolled kiosk record — not a browser heartbeat and not device attestation. Last-seen timestamps are advisory for staff only.

Linux thin clients can optionally use the HMAC device agent in `agent/` (see `agent/README.md`).

### Step 1 — Create an admin user (first time only)

```bash
php artisan admin:create-user admin@yourdistrict.org
```

Sign in at `/admin/login`.

### Step 2 — Create the kiosk record

**Admin dashboard (recommended):**

1. Go to **Kiosks → Create kiosk**.
2. Enter name, school, location, and the device's **allowed IP** (DHCP reservation). This field is required.
3. Copy the **one-time enrollment code** shown after creation (or issue a new code later from the kiosk detail page).

**CLI (alternative):**

```bash
php artisan kiosk:create "Library Front Desk" --school="Main School" --location="Library" --subnet=10.10.20.0/24
php artisan kiosk:enrollment-code {kiosk_id_or_uuid}
```

From the kiosk detail page you can disable the kiosk, reset enrollment, issue a new enrollment code, rotate a device-agent secret, or archive the kiosk.

### Step 3 — Enroll the Chromebook (browser, default)

1. On the kiosk browser, open `https://your-host/kiosk/enroll`.
2. Enter the enrollment code from the admin dashboard and submit.
3. Confirm the completion page shows the kiosk name and that the **DHCP reservation matches `allowed_ip`**.
4. Bookmark `/kiosk/reset` as the kiosk home page. Do not clear cookies on that browser profile.

Browser enrollment does **not** mint a device secret. The browser sends a lightweight session heartbeat for dashboard visibility only; it does not gate student access.

### Step 4 — Linux thin client with device agent (alternative)

Use this path when the device can run systemd and store an HMAC credential. See `agent/README.md` and `docs/INSTALL-UBUNTU.md`.

1. Create the kiosk with `allowed_ip` as above.
2. Enroll via JSON API (`Accept: application/json`) or `sspkiosk-agent enroll` to obtain `kiosk_uuid` + secret.
3. Install and start the agent (`agent/install.sh`, systemd unit).
4. Open `/kiosk/reset` in the kiosk browser.

**Admin provisioning bundle:** After device-agent enrollment or secret rotation, download `agent.conf` once from the kiosk detail page while the secret is still flashed in session.

#### API enrollment (device agent)

```http
POST /kiosk/enroll
Content-Type: application/json
Accept: application/json

{"enrollment_code":"XXXX-XXXX-XXXX"}
```

Response (shown **once**):

```json
{
  "kiosk_id": 1,
  "kiosk_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "secret": "base64-or-hex-secret",
  "message": "Store the secret on the kiosk device. It will not be shown again."
}
```

Store `secret` and `kiosk_uuid` securely on the device, write `/etc/sspkiosk/agent.conf`, and run `sspkiosk-agent`.

### Step 5 — Heartbeat (visibility only)

**Chromebooks (default):** the kiosk layout sends `POST /kiosk/session-heartbeat` from the browser every `KIOSK_HEARTBEAT_INTERVAL_SECONDS` (default 60). This updates **Last seen** in admin for troubleshooting. It does **not** gate student access (`KIOSK_REQUIRE_ACTIVE_HEARTBEAT=false` by default).

**Linux device agent (alternative):** `sspkiosk-agent` sends signed `POST /kiosk/heartbeat` on the same interval. If you set `KIOSK_REQUIRE_ACTIVE_HEARTBEAT=true`, the HMAC path can still enforce freshness for device-agent kiosks via `KioskSecurityService`; browser session heartbeats are never an authorization input.

Schedule `ssp:prune-nonces` hourly (configured in `routes/console.php`) to keep the `used_nonces` table bounded.

### Step 6 — Browser session and IP resolution

Each kiosk should have a **DHCP reservation** stored as `allowed_ip`. `EnsureKioskWebSession` resolves the kiosk from source IP on every request. If the session cookie points at a different kiosk than the source IP, the IP wins and the session is re-bound (`kiosk.session.ip_mismatch` audit event). If the IP resolves nothing, the request goes to `/kiosk/reset/unavailable` — a cookie alone is not trusted.

**Device-agent escape hatch:** if you cannot use a reservation on a Linux thin client, call `POST /kiosk/bind-session` with HMAC headers **and** the browser session cookie.

Browser enrollment sets the session at enroll time; IP resolution covers cookie loss when `allowed_ip` is set.

### HMAC-signed API requests

These endpoints require signed headers:

| Endpoint | Purpose |
|----------|---------|
| `POST /kiosk/heartbeat` | Device-agent last-seen (HMAC) |
| `POST /kiosk/session-heartbeat` | Browser last-seen (session + CSRF; no audit per beat) |
| `POST /kiosk/bind-session` | Attach kiosk to browser session |

| Header | Description |
|--------|-------------|
| `X-Kiosk-Id` | Kiosk UUID |
| `X-Kiosk-Timestamp` | Unix timestamp (seconds) |
| `X-Kiosk-Nonce` | Unique per request |
| `X-Kiosk-Signature` | HMAC-SHA256 hex digest |

Canonical string (newline-separated):

```
{kiosk_uuid}
{timestamp}
{nonce}
{METHOD}
/{path}
{sha256_hex_of_raw_body}
```

Sign with the enrollment secret. Requests are rejected if the timestamp is outside `KIOSK_HMAC_TOLERANCE_SECONDS`, the nonce was reused, the IP is not allowed, or the signature is invalid.

**Student reset forms** (`POST /kiosk/reset/lookup`, photo, submit, password) use the **web session + CSRF only**, not HMAC—they are standard HTML forms in the kiosk browser.

### Troubleshooting kiosk access

| Symptom | Likely cause | Fix |
|---------|----------------|-----|
| Redirect loop or blank page on unavailable | Older builds looped on unavailable | Update; `/kiosk/reset/unavailable` must load without a kiosk session |
| Redirect to `/kiosk/reset/unavailable` | Source IP does not resolve to one enrolled kiosk | Confirm DHCP reservation matches `allowed_ip`; re-enroll after reimage |
| Redirect to `/kiosk/reset/unavailable` | Kiosk archived or not enrolled | Re-enroll in admin; confirm `enrolled_at` is set |
| Stale **Last seen** in admin | Browser tab closed or network issue | Advisory only — does not block `/kiosk/reset` |
| Kiosk disabled | Admin disabled kiosk | Re-enable in admin |
| `401` `invalid_signature` | Wrong secret or body/path mismatch (device agent) | Re-download `agent.conf` after rotation; run `sspkiosk-agent check` |
| `401` `timestamp_expired` | Clock skew on thin client | Enable NTP (`timedatectl set-ntp true`); compare local time to server `Date` header in agent logs |
| `401` `nonce_reused` | Cloned disk image or duplicate agent config | Unique enrollment per device; regenerate secret on one copy |
| `403` `ip_not_allowed` | DHCP changed or kiosk moved VLAN | Update `allowed_ip` / subnet in admin to match reservation |
| `401` on reset form POST | Should not happen | Reset POSTs use session + CSRF only, not HMAC |

### Network and secrets

- Configure `KIOSK_ALLOWED_NETWORKS` and/or per-kiosk `allowed_ip` / `allowed_subnet`.
- Behind a reverse proxy, configure trusted proxies so `$request->ip()` is correct.
- `kiosks.secret_hash` stores the enrollment secret **encrypted** with Laravel `Crypt` (not plaintext).

## Kiosk reset flow (Phase 5+)

After the kiosk is enrolled and the browser session is active (IP-resolved or session-bound):

| Step | Route | Description |
|------|--------|-------------|
| Start | `GET /kiosk/reset` | Enter email or student ID |
| Lookup | `POST /kiosk/reset/lookup` | Session + CSRF; generic message if not found |
| Confirm | `GET /kiosk/reset/confirm` | First name + last initial |
| Photo | `GET/POST /kiosk/reset/photo` | Reset request photo |
| Questions | `GET/POST /kiosk/reset/submit` | Random challenge questions |
| Password (mode 2 only) | `GET/POST /kiosk/reset/password` | Student-selected password + confirmation |
| Pending password (mode 1) | `GET /kiosk/reset/pending-password/{id}` | One-time display before approval |
| Submitted (mode 2) | `GET /kiosk/reset/submitted/{id}` | Confirmation after password entry |

Failed challenge answers do not reveal which were wrong. A successful request queues `SendSlackResetApprovalJob` (Slack message in Phase 6).

## Google OAuth setup (Phase 2)

1. In [Google Cloud Console](https://console.cloud.google.com/), create an OAuth 2.0 **Web application** client for this app.
2. Add authorized redirect URI: `{APP_URL}/auth/google/callback` (must match `GOOGLE_REDIRECT_URI`).
3. Set in `.env`:

   ```env
   STUDENT_GOOGLE_DOMAIN=students.yourdistrict.org
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   ```

4. If you configure `ALLOWED_STUDENT_ORG_UNITS` or `BLOCKED_STAFF_ORG_UNITS`, you must also set the service account values so the app can read each user’s org unit from the Directory API:

   ```env
   ALLOWED_STUDENT_ORG_UNITS=/Students
   BLOCKED_STAFF_ORG_UNITS=/Staff
   GOOGLE_SERVICE_ACCOUNT_JSON_PATH=storage/app/google/service-account.json
   GOOGLE_ADMIN_IMPERSONATION_EMAIL=admin@yourdistrict.org
   ```

5. Student registration routes:
   - `GET /register` — start
   - `GET /auth/google/redirect` — Google sign-in
   - `GET /auth/google/callback` — return from Google
   - `GET /register/continue` — routes to the next registration step
   - `GET /register/questions` — security questions
   - `POST /register/questions` — save questions
   - `GET /register/photo` — webcam registration photo
   - `POST /register/photo` — save photo
   - `GET /register/review` — confirm and finish
   - `POST /register/complete` — mark student registered
   - `GET /register/complete` — success page

Photos are stored on the `local` disk (`storage/app/private`) by default, outside the public web root.

## Required values by workflow

| Workflow | Key `.env` variables |
|----------|----------------------|
| Student Google sign-in (Phase 2+) | `STUDENT_GOOGLE_DOMAIN`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` |
| Google password reset (Phase 7+) | `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`, `GOOGLE_ADMIN_IMPERSONATION_EMAIL` |
| Slack approval (Phase 6+) | `SLACK_BOT_TOKEN`, `SLACK_SIGNING_SECRET`, `SLACK_RESET_CHANNEL_ID`, `SLACK_APPROVER_USERGROUP_ID` |
| Kiosk reset (Phase 4+) | `KIOSK_ALLOWED_NETWORKS` (when `RESET_REQUIRES_KIOSK=true`) |

`App\Services\ConfigurationValidatorService` reports missing required settings. Incomplete config is logged at boot; sensitive routes will fail closed when implemented in later phases.

## Build phases

Development follows `prompts/main.md`:

| Phase | Scope |
|-------|--------|
| 1 | Migrations, models, config, service stubs, audit logging |
| 2 | Google student sign-in and registration |
| 3 | Challenge questions and registration photo |
| 4 | Kiosk enrollment, heartbeat, HMAC validation |
| 5 | Kiosk reset request flow |
| 6 | Slack Block Kit + signature verification |
| 7 | Google Workspace password reset job |
| 8 | Pending password display (pre-approval; updateflow) |
| 9 | Admin dashboard |
| 10 | Tests and security hardening |
| **11** (current) | Ubuntu + Apache + MySQL install guide — [INSTALL-UBUNTU.md](INSTALL-UBUNTU.md) |

## Security hardening (Phase 10)

- HTTP security headers on web routes (`SECURITY_HEADERS_ENABLED`)
- Rate limits: admin login, kiosk reset lookup, kiosk enroll, Slack interactions (see `.env.example`)
- Log redaction for passwords/secrets and generated temp-password patterns
- `php artisan ssp:config-check` — fail if required `.env` values are missing
- Admin photo downloads restricted to safe `student-photos/` paths

Run the full suite: `php artisan test`

## Security note

There is **no** direct student-facing route to reset a Google password. The only path is: validated kiosk → pending encrypted password → Slack approval → queued Google reset using the stored pending password.

## Official references

- [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect)
- [Google Admin SDK Directory API — users](https://developers.google.com/workspace/admin/directory/reference/rest/v1/users)
- [Slack Block Kit](https://docs.slack.dev/block-kit/)
- [Slack request signing](https://docs.slack.dev/authentication/verifying-requests-from-slack)

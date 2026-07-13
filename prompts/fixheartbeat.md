Build Prompt: SSP Kiosk Device Heartbeat Agent (Option B)
Context
This is a Laravel 12 app (sspkiosk) that provides a self-service student password reset kiosk for a K-12 school division. Kiosks are physical devices (Chrome in kiosk mode on a small-form-factor PC / thin client) placed in school libraries and offices.
The server already implements a complete HMAC-authenticated kiosk API. The on-device agent that was supposed to call it was never written. This task is to write that agent and close the gaps in the server-side enrollment path that make the agent impossible to provision.
Current broken behavior

KioskEnrollmentService::enroll() sets kiosks.last_seen_at = now() exactly once, at enrollment.
config/kiosk.php defaults: require_active_heartbeat = true, heartbeat_expires_after_seconds = 180.
EnsureKioskWebSession middleware calls KioskSecurityService::hasFreshHeartbeat() on every /kiosk/reset request.
Nothing ever calls POST /kiosk/heartbeat. So 180 seconds after enrollment, hasFreshHeartbeat() returns false and every reset request redirects to /kiosk/reset/unavailable.
Staff "fix" it by re-enrolling, which touches last_seen_at — and it works for another 3 minutes. This is the reported symptom: "doesn't keep the heartbeat up and times out the session requiring a new enrollment code."

What already exists (do not rewrite)

POST /kiosk/heartbeat → KioskHeartbeatController::store(), wrapped in ValidateKioskRequest middleware. Working.
POST /kiosk/bind-session → KioskSessionController::bind(), ['web', ValidateKioskRequest]. Working.
KioskSecurityService::verifyRequest() — resolves kiosk by X-Kiosk-Id, checks status/enrollment/IP, validates HMAC, enforces nonce single-use. Working.
KioskSecurityService::buildCanonicalPayload() — this is the authoritative signing spec. Do not change it. The agent must match it byte for byte.
KioskCredentialService — generates and encrypts the device secret.
POST /kiosk/enroll → KioskEnrollmentController::enroll(). Partially broken, see below.

The canonical signing contract
From KioskSecurityService::buildCanonicalPayload(), the string to sign is these six fields joined by \n (LF, not CRLF):
{kiosk_uuid}
{unix_timestamp_seconds}
{nonce}
{HTTP_METHOD_UPPERCASE}
/{request_path_no_leading_slash}
{sha256_hex_of_raw_request_body}
Notes on exact semantics — get these wrong and every request 401s:

kiosk_uuid is the value of the X-Kiosk-Id header, which is kiosks.kiosk_uuid (a UUID string), not the integer kiosks.id.
The path segment is '/'.ltrim($request->path(), '/') — Laravel's $request->path() returns the path without a leading slash and without query string. So for POST https://host/kiosk/heartbeat the value is /kiosk/heartbeat.
sha256_hex_of_raw_request_body is hash('sha256', $request->getContent()). For an empty body this is the SHA-256 of the empty string: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855. The agent must hash exactly the bytes it sends, so serialize the JSON body once and reuse that exact string for both hashing and transmission.
Signature = hash_hmac('sha256', $canonical, $secret) → lowercase hex digest.

Headers on every signed request:
HeaderValueX-Kiosk-Idkiosk UUIDX-Kiosk-TimestampUnix seconds, integer, no fractional part (server does ctype_digit())X-Kiosk-NonceUnique per request. UUIDv4 is fine. Server enforces single-use per kiosk via used_noncesX-Kiosk-Signaturelowercase hex HMAC-SHA256
Timestamp tolerance is KIOSK_HMAC_TOLERANCE_SECONDS (default 300). Clock skew is the #1 field failure mode — the agent must fail loudly and distinguishably if the server rejects with timestamp_expired.
Server error responses are JSON: {"message": "...", "reason": "<reason_code>"} with reason codes including missing_kiosk_id, unknown_kiosk, kiosk_disabled, kiosk_not_enrolled, ip_not_allowed, missing_signature_headers, invalid_timestamp, timestamp_expired, nonce_reused, invalid_signature. The agent must log the reason code, not just the HTTP status.

Part 1 — Server-side fixes (prerequisites)
1a. Browser enrollment discards the device secret
In KioskEnrollmentController::enroll(), the JSON branch returns secret, but the non-JSON (browser form) branch throws the secret away and only puts kiosk_id in the session. A kiosk enrolled through the browser therefore has a secret_hash on the server and no secret on the device — it can never sign a heartbeat, and it can never be re-enrolled because enroll() rejects with kiosk_already_enrolled when secret_hash !== null.
Fix: after a successful browser enrollment, display the secret once on a confirmation page, along with a copy-to-clipboard button and the exact provisioning command to run on the device. Flash it via ->with('kiosk_secret', $result['secret']) and render it in a "store this now, it will not be shown again" panel, consistent with how KioskController::rotateSecret() already does it.
Also emit the secret as a downloadable provisioning file (see 1c).
1c. Add a provisioning bundle endpoint
Add an admin-only route that, immediately after enrollment or secret rotation, offers a one-time download of a device config file. This is what makes fleet provisioning tractable — a tech enrolls the kiosk and downloads a file rather than transcribing a 64-char secret.
GET  /admin/kiosks/{kiosk}/provisioning-bundle
Serve the flashed plaintext secret from the session (it is not retrievable from the DB — secret_hash is encrypted, and while KioskCredentialService::decryptSecret() exists, do not expose a route that decrypts on demand). If no secret is in the session, 404. Output:
ini# /etc/sspkiosk/agent.conf
SSPKIOSK_SERVER_URL=https://ssp.lcps.k12.va.us
SSPKIOSK_KIOSK_UUID=<kiosk_uuid>
SSPKIOSK_SECRET=<plaintext_secret>
SSPKIOSK_HEARTBEAT_INTERVAL=60
File permissions on the device must end up 0600 root:root.
1b. Prune used_nonces
Every signed request inserts a row into used_nonces and nothing ever deletes them. At a 60-second heartbeat that is ~1,440 rows per kiosk per day, forever, and nonceWasUsed() queries this table on every request.
Add app/Console/Commands/PruneUsedNoncesCommand.php (ssp:prune-nonces) deleting rows where created_at < now()->subSeconds(config('kiosk.hmac_tolerance_seconds') * 2). Anything older than the tolerance window cannot be replayed — the timestamp check rejects it first. Schedule hourly in routes/console.php or bootstrap/app.php.
1d. Widen the freshness window
Change the heartbeat_expires_after_seconds default from 180 to 300 and document that it should be at least 3× heartbeat_interval_seconds. At the current 60/180 ratio, two consecutive dropped beats (a transient Wi-Fi blip, a DHCP renew) takes the kiosk offline. Add a validation check for this ratio in ConfigurationValidatorService — it already validates other config invariants; follow its existing pattern.

Part 2 — The device agent
Write sspkiosk-agent as a single-file Python 3 script (stdlib only — no pip, no venv; these are locked-down thin clients and dependency management across a fleet is a liability). Target Python 3.10+ as shipped on Ubuntu 22.04/24.04.
Requirements
Config: read from /etc/sspkiosk/agent.conf (the ini file from 1c). Env vars override file values. Fail fast with a clear message if SSPKIOSK_SERVER_URL, SSPKIOSK_KIOSK_UUID, or SSPKIOSK_SECRET is missing.
Signing: implement build_canonical(uuid, ts, nonce, method, path, body_bytes) and sign(canonical, secret) mirroring the PHP exactly. Serialize the JSON body to bytes once and use those same bytes for both hashlib.sha256() and the HTTP write.
Heartbeat loop:

POST {server}/kiosk/heartbeat every SSPKIOSK_HEARTBEAT_INTERVAL seconds.
Body: {"device_fingerprint": "<stable_id>"} — KioskHeartbeatRequest accepts a nullable device_fingerprint string, max 255. Derive it from the machine ID (/etc/machine-id) plus primary MAC; hash it so it's stable but not itself PII.
The server returns heartbeat_interval_seconds in the response. Honor it — if it differs from local config, adopt the server value. This lets you retune the fleet from the server without touching devices.
Add jitter: sleep interval ± up to 10%. Without it, a whole building's kiosks that booted together will thunder the server in lockstep every 60s.

Failure handling — this is the part that matters:

Network error / 5xx → exponential backoff (base 5s, cap at interval), keep retrying forever. Never exit; a kiosk that gives up is a kiosk that needs a truck roll.
401 with reason: timestamp_expired → log at ERROR with both local time and the Date response header so the skew is visible in the log. Attempt to resync via systemd-timesyncd if available; otherwise keep retrying (the clock may correct itself via NTP).
401 with reason: invalid_signature → this is a config error, not a transient one. Log FATAL and keep retrying at a slow interval (5 min), but make the log unmistakable. Do not spin at 60s emitting the same error.
401 with reason: nonce_reused → indicates a nonce collision or a duplicated config file across two devices. Log ERROR loudly (a cloned disk image is the likely cause and it will bite you).
401 with reason: kiosk_disabled / kiosk_not_enrolled → back off to 5 min, keep trying. An admin may re-enable.
403 with reason: ip_not_allowed → the kiosk moved subnets or DHCP changed. Log ERROR with the current local IP.

Logging: structured lines to stdout (systemd captures to journal). Never log the secret, never log the signature. Log the nonce and reason code — those are diagnostically essential and not sensitive.
Signals: handle SIGTERM/SIGINT cleanly for systemctl stop.
CLI:

sspkiosk-agent run — the daemon loop.
sspkiosk-agent check — send one heartbeat, print the full result (status, reason, server time vs local time, computed canonical string with the secret redacted), exit non-zero on failure. This is the field-troubleshooting tool. A tech with a stale kiosk should be able to run this and immediately see whether it's clock skew, a bad secret, or an IP allowlist problem.
sspkiosk-agent enroll --code XXXX-XXXX-XXXX — POST to /kiosk/enroll with Accept: application/json (which hits the JSON branch and returns the secret), then write /etc/sspkiosk/agent.conf with 0600 perms. This makes provisioning a single command.

systemd unit
/etc/systemd/system/sspkiosk-agent.service:

Type=simple, Restart=always, RestartSec=10.
After=network-online.target, Wants=network-online.target.
Run as a dedicated unprivileged user (sspkiosk) that owns /etc/sspkiosk/agent.conf, not root.
Harden: NoNewPrivileges=true, PrivateTmp=true, ProtectSystem=strict, ProtectHome=true, ReadOnlyPaths=/etc/sspkiosk.
No systemd timer — the agent owns its own loop, so restart-on-failure is the only supervision needed.


Part 3 — bind-session (do not skip)
Heartbeat alone proves the device is alive. It does not put the kiosk into the browser's session, which is what EnsureKioskWebSession actually reads (config('kiosk.registration_session_kiosk_key')).
There's an existing fallback in EnsureKioskWebSession that resolves the kiosk by source IP (KioskNetworkService::findEnrolledKioskByIp()) when the session key is absent, and it audit-logs kiosk.session.ip_resolved. That covers most cases — but only if allowed_ip / allowed_subnet is set precisely enough on the kiosk record to identify it uniquely.
So: document clearly that either (a) each kiosk has a distinct allowed_ip (DHCP reservation — recommended), or (b) the browser must call POST /kiosk/bind-session, which requires both HMAC headers and the browser's session cookie — meaning the agent cannot do it alone; it has to be driven from the kiosk browser or a local helper that shares the cookie jar.
Given the IP-resolution fallback already exists and works, recommend DHCP reservations + per-kiosk allowed_ip as the supported path, and treat bind-session as the escape hatch for kiosks that can't get a reservation. State this explicitly in the docs rather than leaving it ambiguous.

Part 4 — Tests
Add tests/Feature/KioskHeartbeatAgentTest.php. tests/Support/SignsKioskRequests.php already exists — extend it rather than duplicating signing logic.
Cover:

Valid signed heartbeat updates last_seen_at and returns heartbeat_interval_seconds.
Heartbeat with a body — verify the body hash is part of the canonical string (send device_fingerprint, then send a request whose signature was computed over a different body, assert 401 invalid_signature).
Replayed nonce → 401 nonce_reused.
Timestamp outside tolerance → 401 timestamp_expired.
A kiosk with a fresh heartbeat can reach /kiosk/reset; the same kiosk after travel(now()->addSeconds(config('kiosk.heartbeat_expires_after_seconds') + 1)) redirects to kiosk.reset.unavailable. This test is the regression guard for the reported bug — it should fail against the current codebase.
ssp:prune-nonces deletes stale rows and leaves in-window rows.
Browser enrollment flashes the secret (assert session()->has('kiosk_secret')).

Add a Python-side test (agent/test_signing.py, stdlib unittest) with a fixed uuid/timestamp/nonce/body and a hardcoded expected signature. Generate the expected value by calling the actual PHP buildCanonicalPayload() + signPayload() in a tinker one-liner and pasting the result in. This cross-language golden-vector test is the single highest-value test in the whole task — it's what catches a \r\n vs \n or a trailing-slash mismatch before it reaches a school.

Part 5 — Documentation
Update docs/SETUP.md § "Registering a kiosk" and docs/INSTALL-UBUNTU.md. The existing docs describe an agent that doesn't exist — replace that with real instructions:

Admin creates the kiosk, sets allowed_ip from the DHCP reservation.
Admin issues an enrollment code.
On the device: install the agent, run sspkiosk-agent enroll --code XXXX-XXXX-XXXX.
systemctl enable --now sspkiosk-agent.
Verify: sspkiosk-agent check and confirm the kiosk shows Online in the admin dashboard (AdminKioskService::isOnline()).

Add a troubleshooting table keyed on the reason codes, mapping each to its actual cause and fix.

Constraints

Do not change buildCanonicalPayload() or signPayload(). The agent conforms to the server, not the reverse.
Do not weaken HMAC verification, nonce single-use, or the timestamp window to make the agent easier to write.
Do not add a route that returns a kiosk's plaintext secret on demand. The secret is shown exactly once, at enrollment or rotation.
Do not add third-party Python dependencies.
Preserve existing audit logging behavior (kiosk.heartbeat events already fire via KioskSecurityService::recordHeartbeat()); add audit events for new admin actions.
This system handles student PII under FERPA. The secret must never appear in logs, in the audit trail, or in any server response outside the one-time enrollment/rotation display.

Deliverables

agent/sspkiosk-agent (Python 3, stdlib only, executable)
agent/test_signing.py (golden-vector cross-language test)
agent/sspkiosk-agent.service (hardened systemd unit)
agent/install.sh (creates the sspkiosk user, installs binary + unit, 0600 config)
Server patches: KioskEnrollmentController secret display, provisioning-bundle route + controller method, PruneUsedNoncesCommand + schedule, config/kiosk.php default change, ConfigurationValidatorService ratio check
tests/Feature/KioskHeartbeatAgentTest.php
Updated docs/SETUP.md and docs/INSTALL-UBUNTU.md

Work through these in order. After the server patches, run the existing suite and confirm nothing in KioskSecurityTest, KioskEnrollmentTest, or KioskIpSessionRecoveryTest regresses before moving to the agent.
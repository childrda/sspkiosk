Build Prompt: Re-architect Kiosk Authorization for Kiosk-Mode Chromebooks
Context
This is sspkiosk, a Laravel 12 self-service student password reset kiosk for a K-12 division (Louisa County Public Schools), FERPA-scoped.
The kiosks are managed Chromebooks running in ChromeOS kiosk mode. A prior task built an on-device HMAC heartbeat agent (agent/ — Python daemon, systemd unit, provisioning bundle). That agent cannot run on ChromeOS. There is no systemd, no shell, no writable /etc, no side process. A kiosk-mode Chromebook runs one thing: a browser session. This was an incorrect hardware assumption and this task corrects for it.
Every Chromebook has a unique DHCP reservation. That is the fact this design rests on.
The design decision
A browser-generated heartbeat proves only that a page capable of sending heartbeats is open. It is not device attestation, and using it as an authorization gate is dishonest about what it demonstrates — while also being fragile, since closing the tab would deauthorize the kiosk.
A reserved IP tied to an enrolled kiosk record is a more useful identifier on a controlled network. It is not inherently stronger as a security control: source IPs can be reassigned, ARP-spoofed, or obscured by NAT/proxies. It works here because the surrounding network is managed, not because the control itself is robust. Do not over-claim this in comments or docs.
Authorization therefore becomes:

Request originates from KIOSK_ALLOWED_NETWORKS
Source IP resolves to exactly one enrolled, active kiosk via findEnrolledKioskByIp()
The kiosk record is active
Session-bound kiosk_id, when present, must agree with the IP-resolved kiosk
Heartbeat is retained for dashboard status, alerting, and troubleshooting only — never authorization

Set KIOSK_REQUIRE_ACTIVE_HEARTBEAT=false as the new default.

Part 1 — Two bugs that block the IP-identity model
Read app/Services/KioskNetworkService.php before writing anything. Both of these will silently defeat the design if left alone.
1a. findEnrolledKioskByIp() requires a device secret
php$eligibleKiosks = Kiosk::query()
    ->whereNotNull('secret_hash')
    ->where('secret_hash', '!=', '')
    ->get()
    ->filter(fn (Kiosk $kiosk) => $kiosk->isActive());
"Enrolled" currently means "has an HMAC device secret." A Chromebook has no agent, so it has no secret, so it can never be resolved by IP. The entire authorization model above fails closed on every kiosk.
Introduce an explicit enrollment concept that doesn't depend on a device credential. Add to kiosks: enrolled_at (nullable timestamp) and enrollment_type (string enum: browser | device_agent). Backfill enrolled_at = created_at and enrollment_type = 'device_agent' for existing rows with a non-null secret_hash; browser for the rest that have been used.
Change findEnrolledKioskByIp() to filter on whereNotNull('enrolled_at') and active status — not on secret_hash. A browser-enrolled Chromebook with a reserved IP must resolve.
Keep KioskSecurityService::verifyRequest() and the HMAC path exactly as they are — a device-agent kiosk (Linux thin client, future or other-division) still authenticates by signature. This change only stops IP resolution from requiring a secret.
1b. isRequestIpAllowed() short-circuits before checking the kiosk
phpforeach (config('kiosk.allowed_networks', []) as $network) {
    if ($this->ipMatchesNetwork($ip, $network)) {
        return true;   // <-- returns before the kiosk's own allowed_ip is ever consulted
    }
}
Any IP inside KIOSK_ALLOWED_NETWORKS is accepted for any kiosk. So if all Chromebooks sit in 10.20.0.0/16, kiosk A's reserved IP is "allowed" for kiosk B — the per-kiosk allowed_ip is decorative. In a model where the reserved IP is the identity, this is the whole ballgame.
Restructure: allowed_networks becomes a coarse outer boundary (must be inside it), and the kiosk's allowed_ip / allowed_subnet becomes a required inner check when a specific $kiosk is supplied. Both must pass. When $kiosk is null (e.g. the enrollment endpoint, which has no kiosk yet), the network check alone stands — that's correct and should stay.
Preserve the existing permissive fallbacks for unconfigured deployments (empty allowed_networks + null allowed_ip/allowed_subnet → allow), so dev and un-migrated installs don't break. But once a kiosk has an allowed_ip, it must match.

Part 2 — Session must agree with IP
app/Http/Middleware/EnsureKioskWebSession.php currently consults the IP only when the session key is absent:
php$kioskId = $request->session()->get($sessionKey);

if (! $kioskId) {
    // resolve by IP...
}

$kiosk = Kiosk::query()->find($kioskId);
A session bound at Kiosk A and later presented from Kiosk B's IP passes unchallenged — the allowlist never gets a look. A stale or copied session cookie decouples the browser from the device. In an IP-identity model this is the hole worth closing, and it costs one comparison.
Restructure handle():

Resolve the kiosk by IP first, always: $resolvedKiosk = $this->networks->findEnrolledKioskByIp($ip).
Read the session-bound kiosk_id.
If both exist and disagree → clear the session key, audit-log kiosk.session.ip_mismatch (include both kiosk ids and the source IP), and re-bind to the IP-resolved kiosk. The IP wins; the network is the authority, not a cookie. Do not abort — a legitimate cause is a Chromebook that was reimaged or a session cookie that outlived a device swap, and aborting strands the kiosk.
If only the session exists and the IP resolves nothing → redirect to kiosk.reset.unavailable. The device is no longer identifiable; do not trust the cookie alone.
If only the IP resolves → bind it to session (existing kiosk.session.ip_resolved audit event, keep it).
If neither → kiosk.reset.unavailable.

Then the existing active/isRequestIpAllowed() checks run as they do now.
Remove the hasFreshHeartbeat() gate from this middleware entirely. Not a config check — delete the block. Heartbeat is no longer an authorization input, and leaving a config-gated branch invites someone to re-enable it later without understanding why it was removed.

Part 3 — Browser heartbeat, for visibility only
Keep last_seen_at fresh so AdminKioskService::isOnline() means something and staff can see at a glance that a building's kiosk has stopped checking in. This is an operations signal, not a security control.
Add a session-authenticated heartbeat route in routes/kiosk.php, inside the ['web', EnsureKioskWebSession::class] group — but not behind EnsureResetPasswordModeConfigured, so a misconfigured reset mode doesn't also blind the dashboard:
phpRoute::post('/session-heartbeat', [KioskHeartbeatController::class, 'sessionHeartbeat'])
    ->name('kiosk.session-heartbeat');
sessionHeartbeat() — pull the kiosk from $request->attributes->get('kiosk') (the middleware sets it), stamp last_seen_at, return JSON with heartbeat_interval_seconds. Do not audit-log every beat — at 60s intervals across a fleet that is thousands of rows a day of pure noise in a FERPA audit trail. The existing KioskSecurityService::recordHeartbeat() writes an audit row per beat; the session heartbeat must not. If you want the HMAC path to keep its audit behavior, leave it; just don't add a second firehose.
In resources/views/layouts/kiosk.blade.php, before </body>:
html<script>
  const HB_URL = "{{ route('kiosk.session-heartbeat') }}";
  const HB_MS  = {{ config('kiosk.heartbeat_interval_seconds') * 1000 }};
  const TOKEN  = "{{ csrf_token() }}";
  const beat = () => fetch(HB_URL, {
      method: 'POST',
      headers: {'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json'},
      credentials: 'same-origin',
      keepalive: true,
  }).catch(() => {});
  beat();
  setInterval(beat, HB_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) beat(); });
</script>
Because the heartbeat no longer gates access, a failed beat is harmless — hence the bare .catch(() => {}). It must never surface an error to a student mid-reset.
Admin UI: on admin/kiosks/index.blade.php and show.blade.php, relabel the online indicator to reflect what it now means. "Online" implies attestation; it doesn't attest to anything. Use "Last seen" with the timestamp, and a stale badge past heartbeat_expires_after_seconds. Add a short note that last-seen is advisory and does not gate kiosk access — so the next person to read this code doesn't reintroduce the old assumption.

Part 4 — Enrollment on ChromeOS
A Chromebook enrolls through the browser: a tech opens /kiosk/enroll in the kiosk session, enters the one-time code, and the session gets kiosk_id.
KioskEnrollmentService::enroll() currently always mints and stores a device secret. For browser enrollment on ChromeOS that secret is useless — nothing can ever sign with it, and KioskEnrollmentController already displays-then-discards it.
Split the paths:

Browser enrollment (non-JSON request): set enrolled_at = now(), enrollment_type = 'browser', leave secret_hash null. Do not generate a secret. Redirect to a confirmation page that shows the kiosk name and reminds the tech to confirm the DHCP reservation matches the kiosk's allowed_ip — that reservation is now the device identity, and a mismatch is the failure mode that will strand a building.
API enrollment (Accept: application/json): unchanged. Mint the secret, set enrollment_type = 'device_agent', return it. This keeps the Linux/thin-client path alive.

AdminKioskService::issueEnrollmentCode() currently throws if credentials->isEnrolled($kiosk) (i.e. secret_hash !== null). Change the guard to key off enrolled_at, so a browser-enrolled kiosk can't be silently re-enrolled either — and so a tech can re-issue a code for a Chromebook that got reimaged. Provide an explicit admin "re-enroll" action that clears enrolled_at (and secret_hash), audit-logged as admin.kiosk.reenrollment_reset.
AdminKioskService::archive() must also null enrolled_at, alongside the existing secret_hash null. Otherwise an archived kiosk remains IP-resolvable.
StoreKioskRequest should require allowed_ip when creating a kiosk (or warn loudly if omitted). A kiosk with no allowed_ip in this model has no identity.

Part 5 — Fate of agent/
Keep the directory. Scope it explicitly.
The HMAC agent is correct, verified code (golden-vector test passes against the live PHP canonical string) and it is the right answer for any future Linux thin client or for a building that cannot get DHCP reservations — the "durable device credential" case. Deleting it means rebuilding it.
But it is now dead code on the current fleet, and dead code in a security-sensitive repo is a liability. Someone reads the docs in eighteen months and builds on an assumption that was never true here.

Add agent/README.md whose first line reads: This agent does not run on ChromeOS. Kiosk-mode Chromebooks authorize by reserved IP + enrolled kiosk record and do not use this agent. This is for Linux thin clients only.
Update docs/SETUP.md and docs/INSTALL-UBUNTU.md to lead with the Chromebook path (browser enroll + DHCP reservation) as the supported default, with the device-agent path clearly marked as an alternative for Linux devices.
Do not delete agent/, KioskSecurityService, ValidateKioskRequest, or the HMAC routes. Both enrollment types remain supported.


Tests
Add tests/Feature/KioskIpAuthorizationTest.php. tests/Feature/KioskIpSessionRecoveryTest.php already exists and exercises the IP fallback — extend rather than duplicate, and expect to update it.

A browser-enrolled kiosk with no secret_hash but a matching allowed_ip resolves via findEnrolledKioskByIp() (regression guard for bug 1a — fails against current code)
A request from inside allowed_networks but not matching the kiosk's allowed_ip is rejected for that kiosk (regression guard for bug 1b)
Session bound to kiosk A + request from kiosk B's IP → session re-bound to B, kiosk.session.ip_mismatch audit row written, request proceeds as B
Session bound to kiosk A + IP resolves nothing → redirect to kiosk.reset.unavailable
Two kiosks sharing an allowed_ip → findEnrolledKioskByIp() returns null (ambiguity fails closed; existing behavior, lock it in)
/kiosk/reset is reachable with a stale last_seen_at (heartbeat no longer gates — this is the core behavioral change)
POST /kiosk/session-heartbeat updates last_seen_at and writes no audit row
Archived kiosk is not IP-resolvable (enrolled_at nulled)
HMAC heartbeat path still works end-to-end for a device_agent kiosk (don't regress the Linux path)


Constraints

Do not delete the HMAC/agent subsystem. Both enrollment types stay supported.
Do not make the browser heartbeat an authorization input, and do not leave a config flag that would let someone re-enable it as one.
Do not audit-log per-beat on the session heartbeat.
Do not claim in comments or docs that reserved IPs are a strong security control. They are a useful identifier on a managed network. Say that.
Do not touch OfficeVerificationService, the archive/soft-delete work, the roster export, or label printing. All verified green.
Preserve existing audit-log patterns and the service-layer architecture.

Deliverables

Migration: kiosks.enrolled_at, kiosks.enrollment_type + backfill
KioskNetworkService::findEnrolledKioskByIp() — key off enrolled_at, not secret_hash
KioskNetworkService::isRequestIpAllowed() — network as outer bound, kiosk IP as required inner check
EnsureKioskWebSession — IP-first resolution, session/IP agreement check, heartbeat gate removed
KioskHeartbeatController::sessionHeartbeat() + route + layouts/kiosk.blade.php script
KioskEnrollmentService / KioskEnrollmentController — browser vs device-agent split
AdminKioskService — enrolled_at in archive, re-enroll action, enrollment-code guard
StoreKioskRequest — require allowed_ip
Admin UI: "Last seen" relabel + advisory note
agent/README.md scoping note; docs/SETUP.md + INSTALL-UBUNTU.md lead with the Chromebook path
.env.example: KIOSK_REQUIRE_ACTIVE_HEARTBEAT=false
tests/Feature/KioskIpAuthorizationTest.php

Build Part 1 first and run the full suite — KioskSecurityTest, KioskEnrollmentTest, and KioskIpSessionRecoveryTest all exercise KioskNetworkService and will need updating. Those tests currently encode the secret-dependent enrollment assumption; make them green by updating assertions to the new model, not by restoring the secret_hash filter.
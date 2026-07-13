This agent does not run on ChromeOS. Kiosk-mode Chromebooks authorize by reserved IP plus an enrolled kiosk record and do not use this agent. This package is for Linux thin clients only.

## When to use this agent

Use the Python heartbeat agent when a kiosk device can run a side process (systemd) and store an HMAC device credential — for example a Linux thin client, or a site that cannot rely on per-device DHCP reservations.

## Chromebook (default fleet)

1. Create the kiosk in admin with a unique **allowed IP** matching the Chromebook DHCP reservation.
2. Issue an enrollment code and open `/kiosk/enroll` in the kiosk browser session.
3. Enter the code. Browser enrollment does **not** mint a device secret.
4. The reserved IP identifies the device on your managed network. Last-seen heartbeats from the browser are advisory only.

See `docs/SETUP.md` for the full Chromebook path.

## Linux thin client

1. Enroll via JSON API (`Accept: application/json`) or admin provisioning bundle to obtain `kiosk_uuid` + secret.
2. Install with `agent/install.sh` and configure `agent.conf`.
3. The agent signs `POST /kiosk/heartbeat` with HMAC. This path remains fully supported.

## Tests

Run `python agent/test_signing.py` to verify the canonical signing string against the live PHP implementation.

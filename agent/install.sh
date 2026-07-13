#!/usr/bin/env bash
set -euo pipefail

AGENT_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sspkiosk-agent"
AGENT_DEST="/usr/local/bin/sspkiosk-agent"
SERVICE_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sspkiosk-agent.service"
SERVICE_DEST="/etc/systemd/system/sspkiosk-agent.service"
CONFIG_DIR="/etc/sspkiosk"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo bash install.sh" >&2
  exit 1
fi

if ! id sspkiosk >/dev/null 2>&1; then
  useradd --system --home-dir "${CONFIG_DIR}" --shell /usr/sbin/nologin sspkiosk
fi

install -d -m 0750 -o sspkiosk -g sspkiosk "${CONFIG_DIR}"
install -m 0755 "${AGENT_SRC}" "${AGENT_DEST}"
install -m 0644 "${SERVICE_SRC}" "${SERVICE_DEST}"

if [[ ! -f "${CONFIG_DIR}/agent.conf" ]]; then
  echo "No ${CONFIG_DIR}/agent.conf yet."
  echo "Enroll with: sudo ${AGENT_DEST} enroll --code XXXX-XXXX-XXXX --server https://your-host"
fi

systemctl daemon-reload
echo "Installed ${AGENT_DEST}"
echo "Enable with: systemctl enable --now sspkiosk-agent"

<?php

namespace App\Services;

class KioskProvisioningService
{
    public function buildAgentConfigIni(string $kioskUuid, string $secret): string
    {
        $serverUrl = rtrim((string) config('app.url'), '/');
        $interval = (int) config('kiosk.heartbeat_interval_seconds');

        return implode("\n", [
            '# /etc/sspkiosk/agent.conf',
            'SSPKIOSK_SERVER_URL='.$serverUrl,
            'SSPKIOSK_KIOSK_UUID='.$kioskUuid,
            'SSPKIOSK_SECRET='.$secret,
            'SSPKIOSK_HEARTBEAT_INTERVAL='.$interval,
            '',
        ]);
    }
}

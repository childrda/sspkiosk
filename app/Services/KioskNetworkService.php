<?php

namespace App\Services;

use App\Models\Kiosk;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class KioskNetworkService
{
    public function isRequestIpAllowed(Request $request, ?Kiosk $kiosk = null): bool
    {
        $ip = $request->ip();

        if ($ip === null) {
            return false;
        }

        foreach (config('kiosk.allowed_networks', []) as $network) {
            if ($this->ipMatchesNetwork($ip, $network)) {
                return true;
            }
        }

        if ($kiosk === null) {
            return config('kiosk.allowed_networks') === [];
        }

        if ($kiosk->allowed_ip && $ip === $kiosk->allowed_ip) {
            return true;
        }

        if ($kiosk->allowed_subnet && $this->ipMatchesNetwork($ip, $kiosk->allowed_subnet)) {
            return true;
        }

        return $kiosk->allowed_ip === null
            && $kiosk->allowed_subnet === null
            && config('kiosk.allowed_networks') === [];
    }

    public function findEnrolledKioskByIp(string $ip): ?Kiosk
    {
        $eligibleKiosks = Kiosk::query()
            ->whereNotNull('secret_hash')
            ->where('secret_hash', '!=', '')
            ->get()
            ->filter(fn (Kiosk $kiosk) => $kiosk->isActive());

        $exactMatches = $eligibleKiosks
            ->filter(fn (Kiosk $kiosk) => filled($kiosk->allowed_ip) && $kiosk->allowed_ip === $ip)
            ->values();

        if ($exactMatches->count() === 1) {
            return $exactMatches->first();
        }

        if ($exactMatches->count() > 1) {
            return null;
        }

        $subnetMatches = $eligibleKiosks
            ->filter(fn (Kiosk $kiosk) => filled($kiosk->allowed_subnet)
                && $this->ipMatchesNetwork($ip, $kiosk->allowed_subnet))
            ->values();

        return $subnetMatches->count() === 1
            ? $subnetMatches->first()
            : null;
    }

    public function ipMatchesNetwork(string $ip, string $network): bool
    {
        $network = trim($network);

        if ($network === '') {
            return false;
        }

        if (! str_contains($network, '/')) {
            return $ip === $network;
        }

        return IpUtils::checkIp($ip, $network);
    }
}

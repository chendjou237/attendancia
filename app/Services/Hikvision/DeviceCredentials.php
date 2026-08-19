<?php

namespace App\Services\Hikvision;

use App\Models\Device;

/**
 * Device IP/corridor/active-status live in the `devices` table; only
 * the ISAPI username/password are configuration (§5 — never committed).
 * A per-device override keyed by serial falls back to one default pair,
 * since the pilot has exactly one terminal and production's several
 * will most likely share one ISAPI account.
 */
class DeviceCredentials
{
    /**
     * @return array{user: ?string, pass: ?string}
     */
    public static function for(Device $device): array
    {
        $override = config("attendance.credentials.{$device->serial}");

        return [
            'user' => $override['user'] ?? config('attendance.default_user'),
            'pass' => $override['pass'] ?? config('attendance.default_pass'),
        ];
    }
}

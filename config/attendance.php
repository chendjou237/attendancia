<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School timezone
    |--------------------------------------------------------------------------
    |
    | period_slots.start_time/end_time are wall-clock times as the school
    | thinks about them. Everything is stored in UTC (§14); this is the
    | offset used to convert a slot's local time into the UTC instant it
    | actually falls on. Cameroon is WAT (UTC+1) year-round, no DST.
    |
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Africa/Douala'),

    /*
    |--------------------------------------------------------------------------
    | §7.4 Location validation
    |--------------------------------------------------------------------------
    |
    | Because each corridor has its own terminal, a scan carries location:
    | a mismatch between the device a teacher scanned on and their
    | expected session's corridor is a real fraud/error signal. But with
    | one pilot device, most scans will legitimately come from teachers
    | whose real classroom is a corridor that has no terminal yet — that
    | isn't fraud, it's incomplete coverage. Off by default so the pilot
    | doesn't drown in false anomalies; PairingEngine still logs what it
    | would have flagged, so this can be tuned before more corridors are
    | wired and the flag flips on.
    |
    */
    'enforce_location' => env('ATTENDANCE_ENFORCE_LOCATION', false),

    /*
    |--------------------------------------------------------------------------
    | Hikvision ISAPI credentials
    |--------------------------------------------------------------------------
    |
    | Device IP, corridor, and active status live in the `devices` table
    | (managed via the admin panel) — that's operational data, not a
    | secret. Only the ISAPI username/password are configured here, kept
    | out of the database and out of git (§5: "device IPs, credentials...
    | never get committed. .env only, with matching keys in .env.example").
    |
    | 'credentials' allows a per-device override (keyed by devices.serial)
    | for when production's multiple terminals don't all share one
    | account; anything not listed there falls back to the default pair.
    |
    */
    'default_user' => env('HIKVISION_USER'),
    'default_pass' => env('HIKVISION_PASS'),
    'credentials' => [
        // 'DEV0001234' => ['user' => env('HIKVISION_DEV0001234_USER'), 'pass' => env('HIKVISION_DEV0001234_PASS')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream worker
    |--------------------------------------------------------------------------
    |
    | idle_timeout: seconds of silence before the worker assumes a
    | half-open connection and exits for Supervisor to reconnect. The
    | device's own videoloss heartbeats mean genuine silence always
    | means something is wrong with the connection, not the device.
    |
    */
    'idle_timeout' => env('HIKVISION_IDLE_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Backfill window
    |--------------------------------------------------------------------------
    |
    | How far back hikvision:backfill looks by default. 48h covers a
    | deploy or a short restart; a longer outage needs a manual
    | --hours=N run once the device's actual storage capacity is known
    | (Phase C1 reconnaissance reads this from the device directly).
    |
    */
    'backfill_hours' => env('HIKVISION_BACKFILL_HOURS', 48),

];

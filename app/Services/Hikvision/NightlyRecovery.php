<?php

namespace App\Services\Hikvision;

use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The nightly half of §7.2's "scheduled nightly and on worker boot"
 * backfill — but backfill alone only recovers raw_events. Nothing
 * turns those into period_results except a human running
 * attendance:compute for the affected date, which has no way to know
 * an outage happened or which date it hit. Backfill was already
 * unconditionally correct and idempotent (§14) before this existed;
 * this closes the other half of the loop so a routine overnight
 * outage self-heals completely, not just at the raw-data layer.
 *
 * Recomputes a fixed 2-day trailing window (today and yesterday) —
 * deliberately not "every date backfill touched," which would need
 * backfill to report that back in a structured way it doesn't. Two
 * days covers the overwhelmingly common case (an outage that started
 * yesterday evening, recovered overnight); attendance:compute is
 * idempotent, so recomputing a date nothing changed on is a cheap
 * no-op, not a risk. A longer outage — the device's own storage
 * capacity is the real constraint on those, see docs/setup.md's power
 * model section — needs a human to run `attendance:compute
 * --date=YYYY-MM-DD` for each earlier affected day once it's noticed,
 * the same way a longer-than-default outage already needs `hikvision:
 * backfill --hours=N`.
 */
class NightlyRecovery
{
    public function run(): void
    {
        Device::query()->where('is_active', true)->get()
            ->each(fn (Device $device) => Artisan::call('hikvision:backfill', ['device' => $device->serial]));

        foreach ([Carbon::today(), Carbon::yesterday()] as $date) {
            Artisan::call('attendance:compute', ['date' => $date->toDateString()]);
        }
    }
}

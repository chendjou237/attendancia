<?php

use App\Services\Hikvision\NightlyRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §7.2: "scheduled nightly and on worker boot" — boot-time backfill is
// wired into hikvision:stream itself; this covers the nightly half:
// backfill every active device, then recompute the trailing window so
// a routine overnight outage self-heals all the way to period_results,
// not just raw_events (see NightlyRecovery's own docblock for why).
Schedule::call(fn () => app(NightlyRecovery::class)->run())
    ->dailyAt('02:00')->name('hikvision-nightly-backfill')->withoutOverlapping();

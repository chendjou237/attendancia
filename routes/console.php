<?php

use App\Services\Hikvision\NightlyRecovery;
use Carbon\Carbon;
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
//
// withoutOverlapping(30) rather than bare: the default lock lives for
// 1440 minutes, so one hard-killed run (a service restart mid-backfill)
// silently suppresses the *following* night as well. Thirty minutes is
// comfortably longer than the job and short enough to self-clear.
Schedule::call(fn () => app(NightlyRecovery::class)->run())
    ->dailyAt('02:00')->name('hikvision-nightly-backfill')->withoutOverlapping(30);

// Attendance only exists once attendance:compute has run: raw_events
// arriving from the terminal never recompute anything by themselves
// (EventProcessor inserts the row and returns — there is no job, event
// or observer behind it). Before this entry the only automated caller
// was the 02:00 recovery above, whose "today" is a day nobody has
// scanned on yet — so every scan sat unaccounted until 02:00 the next
// morning, and the panel showed a full day of "no scan-in" to staff
// watching teachers tap in front of them. Recomputing the current date
// through the school day closes that gap.
//
// A closure, not Schedule::command() with a date argument: command
// arguments are built once when schedule:work boots and would pin this
// to whatever day the service last started. The date is resolved in the
// school timezone (not Carbon::today(), which is UTC and names the
// wrong day between local midnight and 01:00), and ->timezone() before
// ->between() makes the window local wall clock.
Schedule::call(fn () => Artisan::call('attendance:compute', [
    'date' => Carbon::now(config('attendance.timezone'))->toDateString(),
]))
    ->everyTenMinutes()
    ->timezone(config('attendance.timezone'))
    ->between('06:00', '20:00')
    ->name('attendance-compute-today')
    ->withoutOverlapping(15);

<?php

use App\Models\Device;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §7.2: "scheduled nightly and on worker boot" — boot-time backfill is
// wired into hikvision:stream itself; this covers the nightly half for
// every active device, at an hour the school is closed.
Schedule::call(function () {
    Device::where('is_active', true)->get()->each(
        fn (Device $device) => Artisan::call('hikvision:backfill', ['device' => $device->serial])
    );
})->dailyAt('02:00')->name('hikvision-nightly-backfill')->withoutOverlapping();

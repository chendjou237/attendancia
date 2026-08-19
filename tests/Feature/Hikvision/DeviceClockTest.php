<?php

use App\Models\Corridor;
use App\Models\Device;
use App\Services\Hikvision\DeviceClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('stores the offset when the device clock matches server time', function () {
    Carbon::setTestNow('2026-08-18 09:00:00');
    Http::fake(['*System/time*' => Http::response(['Time' => ['localTime' => '2026-08-18T09:00:00+00:00']])]);
    $device = Device::factory()->for(Corridor::factory())->create(['last_time_offset_seconds' => null]);

    (new DeviceClock)->poll($device, 'user', 'pass');

    expect($device->fresh()->last_time_offset_seconds)->toBe(0);
    Carbon::setTestNow();
});

it('flags drift beyond the configured threshold', function () {
    Carbon::setTestNow('2026-08-18 09:00:00');
    config(['attendance.clock_drift_threshold_seconds' => 240]);
    // Device clock is 10 minutes ahead.
    Http::fake(['*System/time*' => Http::response(['Time' => ['localTime' => '2026-08-18T09:10:00+00:00']])]);
    $device = Device::factory()->for(Corridor::factory())->create();

    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('attendance')->andReturn($spy);

    (new DeviceClock)->poll($device, 'user', 'pass');

    expect($device->fresh()->last_time_offset_seconds)->toBe(600);
    $spy->shouldHaveReceived('warning')->with('Device clock drift exceeds threshold', Mockery::any());
    Carbon::setTestNow();
});

it('logs a warning without throwing when the response has no recognisable time field', function () {
    Http::fake(['*System/time*' => Http::response(['unexpected' => 'shape'])]);
    $device = Device::factory()->for(Corridor::factory())->create(['last_time_offset_seconds' => null]);

    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('attendance')->andReturn($spy);

    (new DeviceClock)->poll($device, 'user', 'pass');

    $spy->shouldHaveReceived('warning');
    expect($device->fresh()->last_time_offset_seconds)->toBeNull();
});

<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The in-day compute entry is load-bearing: without it nothing turns a
 * scan into a period_result until 02:00 the next morning, which is the
 * production failure this test exists to prevent from coming back.
 */
function scheduledEvent(string $name): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === $name || $event->getSummaryForDisplay() === $name);
}

it('recomputes the current day every ten minutes through the school day', function () {
    $event = scheduledEvent('attendance-compute-today');

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/10 * * * *');
});

it('runs the in-day recompute in the school timezone, not UTC', function () {
    $event = scheduledEvent('attendance-compute-today');

    expect($event->timezone)->toBe(config('attendance.timezone'));
});

it('still runs the nightly backfill and recovery at 02:00', function () {
    $event = scheduledEvent('hikvision-nightly-backfill');

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 2 * * *');
});

// A bare withoutOverlapping() holds its lock for 1440 minutes, so one
// hard-killed run suppresses the *following* night as well — on a box
// where the scheduler is an NSSM service that gets restarted, that is a
// silent two-day outage.
it('expires its overlap locks well inside a day', function () {
    foreach (['attendance-compute-today', 'hikvision-nightly-backfill'] as $name) {
        expect(scheduledEvent($name)->expiresAt)->toBeLessThan(1440);
    }
});

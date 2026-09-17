<?php

use App\Models\AttendanceSession;
use App\Models\PeriodResult;
use Tests\Support\AttendanceFixture;

it('writes nothing — no sessions, no results — so it is safe against production', function () {
    $f = AttendanceFixture::make();
    $f->scanAt('07:28:00');

    $this->artisan('attendance:explain', [
        'date' => $f->date->toDateString(),
        '--teacher' => $f->teacher->staff_no,
    ])->assertSuccessful();

    expect(AttendanceSession::count())->toBe(0);
    expect(PeriodResult::count())->toBe(0);
});

it('reports the scans it found for the teacher that day', function () {
    $f = AttendanceFixture::make();
    $f->scanAt('07:28:00');
    $f->scanAt('08:20:00');

    $this->artisan('attendance:explain', [
        'date' => $f->date->toDateString(),
        '--teacher' => $f->teacher->staff_no,
    ])
        ->expectsOutputToContain('Scans resolved to this teacher on '.$f->date->toDateString().': 2')
        ->assertSuccessful();
});

// The distinction that matters in a dispute: "nobody scanned" and "a
// scan exists but no compute has ever run" are different answers.
it('says when no compute has ever written a result for the day', function () {
    $f = AttendanceFixture::make();
    $f->scanAt('07:28:00');

    $this->artisan('attendance:explain', [
        'date' => $f->date->toDateString(),
        '--teacher' => $f->teacher->staff_no,
    ])
        ->expectsOutputToContain('attendance:compute has never run')
        ->assertSuccessful();
});

it('reports the stored status once the day has been computed', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();

    $this->artisan('attendance:explain', [
        'date' => $f->date->toDateString(),
        '--teacher' => $f->teacher->staff_no,
    ])
        ->expectsOutputToContain('present')
        ->assertSuccessful();
});

it('fails clearly for an unknown staff number', function () {
    AttendanceFixture::make();

    $this->artisan('attendance:explain', ['date' => '2026-09-17', '--teacher' => 'NOPE'])
        ->assertFailed();
});

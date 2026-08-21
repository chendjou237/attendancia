<?php

use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Regression: date columns must compare correctly when the reference
// value IS the boundary day — a version whose valid_from is exactly the
// date being resolved has to be found, not skipped.
//
// This used to break because Laravel's `date` cast serialised to
// "Y-m-d H:i:s", so a stored value sorted *after* the bare "Y-m-d" it
// was compared against. MySQL's real DATE column masked it by
// discarding the time on write; only the SQLite test suite caught it.
// App\Casts\DateOnly now canonicalises storage to "Y-m-d" on every
// engine, so plain where() comparisons are correct — and these tests
// are what hold that guarantee down.

it('stores a date attribute as a bare Y-m-d string, with no time component', function () {
    $slot = PeriodSlot::factory()->create(['valid_from' => '2026-09-01', 'valid_to' => null]);

    expect(DB::table('period_slots')->where('id', $slot->id)->value('valid_from'))
        ->toBe('2026-09-01');
});

it('truncates a full datetime down to its date part on write', function () {
    $slot = PeriodSlot::factory()->create(['valid_from' => Carbon::parse('2026-09-01 17:42:09')]);

    expect(DB::table('period_slots')->where('id', $slot->id)->value('valid_from'))
        ->toBe('2026-09-01');
});

it('reads a date attribute back as a Carbon at the start of the day', function () {
    $slot = PeriodSlot::factory()->create(['valid_from' => '2026-09-01'])->fresh();

    expect($slot->valid_from)->toBeInstanceOf(Carbon::class)
        ->and($slot->valid_from->toDateTimeString())->toBe('2026-09-01 00:00:00');
});

// The closing boundary, mirroring the valid_from cases below: a version
// whose valid_to IS the reference day is still in force that day.
it('resolves a period slot whose valid_to is the exact reference day', function () {
    $date = Carbon::parse('2026-09-01');

    PeriodSlot::factory()->create([
        'day_of_week' => $date->dayOfWeek, 'seq' => 1,
        'valid_from' => '2026-08-01', 'valid_to' => '2026-09-01',
    ]);

    expect(PeriodSlot::forDate($date))->toHaveCount(1);
});

it('resolves a period slot whose valid_from is the exact reference day', function () {
    $date = Carbon::parse('2026-09-01');
    PeriodSlot::factory()->create(['day_of_week' => $date->dayOfWeek, 'seq' => 1, 'valid_from' => '2026-09-01']);

    expect(PeriodSlot::forDate($date))->toHaveCount(1);
});

it('resolves a rule_version whose valid_from is the exact reference day', function () {
    RuleVersion::factory()->create(['valid_from' => '2026-09-01']);

    expect(RuleVersion::forDate(Carbon::parse('2026-09-01')))->not->toBeNull();
});

it("resolves a teacher's timetable version whose valid_from is the exact reference day", function () {
    $teacher = Teacher::factory()->create();
    TimetableVersion::factory()->for($teacher)->create(['valid_from' => '2026-09-01']);

    expect($teacher->timetableVersionFor(Carbon::parse('2026-09-01')))->not->toBeNull();
});

it('includes a teacher whose active_from is the exact reference day in attendance:compute', function () {
    Teacher::factory()->create(['active_from' => '2026-09-01']);
    RuleVersion::factory()->create(['valid_from' => '2026-01-01']);

    $this->artisan('attendance:compute', ['date' => '2026-09-01'])
        ->expectsOutputToContain('1 succeeded')
        ->assertSuccessful();
});

<?php

use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableVersion;
use Carbon\Carbon;

// Regression: the `date` cast serialises to "Y-m-d H:i:s" on save. A
// plain where('col', '<=', 'Y-m-d') string comparison then silently
// excludes a row whose date is the exact reference day, because the
// stored value sorts *after* the bare date string. MySQL's real DATE
// column type masks this (it discards the time part on write), which is
// exactly why this went unnoticed until the SQLite test suite caught it
// — see PeriodSlot::forDate(), RuleVersion::forDate(),
// Teacher::timetableVersionFor(), and ComputeAttendance's teacher filter.
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

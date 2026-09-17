<?php

use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Tests\Support\AttendanceFixture;

it('computes results for every active teacher on a date, never silently absent for one who did not scan', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    $teacher2 = Teacher::factory()->create();
    $tv2 = TimetableVersion::factory()->for($teacher2)->create();
    TimetableEntry::factory()->for($tv2, 'version')->create([
        'day_of_week' => 1, 'slot_id' => $f->slot->id, 'class_code_id' => $f->classCode->id, 'room_id' => $f->room->id,
    ]);
    // teacher2 never scans

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])
        ->assertSuccessful();

    $r1 = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    $r2 = PeriodResult::current()->where('teacher_id', $teacher2->id)->first();

    expect($r1->status->value)->toBe('present');
    expect($r2->status->value)->toBe('unpaired'); // never a silent absent
});

it('filters to a specific teacher via --teacher', function () {
    $f = AttendanceFixture::make();
    $teacher2 = Teacher::factory()->create();
    $tv2 = TimetableVersion::factory()->for($teacher2)->create();
    TimetableEntry::factory()->for($tv2, 'version')->create([
        'day_of_week' => 1, 'slot_id' => $f->slot->id, 'class_code_id' => $f->classCode->id, 'room_id' => $f->room->id,
    ]);

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString(), '--teacher' => [$f->teacher->staff_no]])
        ->assertSuccessful();

    expect(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(1);
    expect(PeriodResult::where('teacher_id', $teacher2->id)->count())->toBe(0);
});

it('fails when no rule_version is active for the date', function () {
    $this->artisan('attendance:compute', ['date' => '2000-01-01'])
        ->assertFailed();
});

it('is idempotent when run twice', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();
    $firstId = PeriodResult::current()->first()->id;

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();

    expect(PeriodResult::count())->toBe(1);
    expect(PeriodResult::current()->first()->id)->toBe($firstId);
});

// The recovery path for a backlog: nothing revisits a date once it drops
// out of NightlyRecovery's two-day window, so a stretch of days whose
// scans were never computed has to be catchable in one command.
it('computes every date in a --from/--to range', function () {
    $f = AttendanceFixture::make();
    $monday = $f->date->clone();
    $tuesday = $monday->clone()->addDay();

    // A Tuesday slot and entry, so the range covers two days that both
    // expect a session rather than one teaching day and one empty one.
    $tuesdaySlot = PeriodSlot::factory()->create([
        'day_of_week' => 2, 'seq' => 1, 'start_time' => '07:30:00', 'end_time' => '08:25:00', 'is_break' => false,
    ]);
    TimetableEntry::factory()->for($f->timetableVersion, 'version')->create([
        'day_of_week' => 2, 'slot_id' => $tuesdaySlot->id, 'class_code_id' => $f->classCode->id, 'room_id' => $f->room->id,
    ]);

    $this->artisan('attendance:compute', [
        '--from' => $monday->toDateString(),
        '--to' => $tuesday->toDateString(),
    ])->assertSuccessful();

    expect(PeriodResult::current()->where('date', $monday->toDateString())->count())->toBe(1);
    expect(PeriodResult::current()->where('date', $tuesday->toDateString())->count())->toBe(1);
});

it('rejects a range whose --to is before its --from', function () {
    AttendanceFixture::make();

    $this->artisan('attendance:compute', ['--from' => '2026-09-17', '--to' => '2026-09-10'])
        ->assertFailed();
});

it('rejects --to without --from rather than silently computing one day', function () {
    AttendanceFixture::make();

    $this->artisan('attendance:compute', ['--to' => '2026-09-17'])->assertFailed();
});

// The production failure this range exists for: a day is computed before
// anyone has scanned (NightlyRecovery's "today" at 02:00), the teacher
// then scans, and nothing looks at that date again. Recomputing must
// pick the scans up.
it('picks up scans that arrived after an earlier compute left the day unpaired', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();
    expect(PeriodResult::current()->first()->status->value)->toBe('unpaired');

    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();

    expect(PeriodResult::current()->first()->status->value)->toBe('present');
});

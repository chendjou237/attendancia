<?php

use App\Models\PeriodResult;
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

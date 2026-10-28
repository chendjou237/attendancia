<?php

use App\Enums\DayType;
use App\Enums\PeriodStatus;
use App\Models\CalendarDay;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\TimetableEntry;
use App\Services\Attendance\DayResolver;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use App\Services\Reporting\MonthlyReportGenerator;
use Tests\Support\AttendanceFixture;

/**
 * Suspended classes are not expected that day — removed from the
 * month's count like a holiday, not paid as PRESENT_ADMIN (the rule until
 * 2026-10). The out-of-scope path (normal scans) is covered in
 * DayResolverTest.php; this file covers the whole-school scope, a teacher
 * who scanned anyway, retro-marking an already-computed day, and what the
 * monthly report counts.
 */
function suspensionResolver(): DayResolver
{
    $builder = new SessionBuilder;
    $writer = new PeriodResultWriter;

    return new DayResolver($builder, new PairingEngine, new RuleEngine($builder, $writer));
}

it('suspends every class when no class codes are attached to the calendar day (whole-school scope)', function () {
    $f = AttendanceFixture::make();
    // Empty scope, per CalendarDay::suspendsClassCode()'s "scoped
    // isEmpty() -> whole school" rule — no ->attach() call at all.
    CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(0);
});

it('ignores a scan on a suspended day — nothing is expected, so nothing is counted', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::current()->where('teacher_id', $f->teacher->id)->count())->toBe(0);
});

it('retro-marking an already-computed day as suspended retires its results without deleting them', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);
    expect(PeriodResult::current()->where('teacher_id', $f->teacher->id)->sole()->status)->toBe(PeriodStatus::Present);

    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);
    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::current()->where('teacher_id', $f->teacher->id)->count())->toBe(0)
        ->and(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(1); // kept, not current

    // Un-marking brings the day back through the normal path.
    $calendarDay->delete();
    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);
    expect(PeriodResult::current()->where('teacher_id', $f->teacher->id)->sole()->status)->toBe(PeriodStatus::Present);
});

it('leaves suspended periods out of the monthly total while other classes still count', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00'], ['08:25:00', '09:20:00']]);
    // Period 2 is another class, not in the suspension scope.
    $otherClass = ClassCode::factory()->create();
    TimetableEntry::where('slot_id', PeriodSlot::where('seq', 2)->value('id'))->update(['class_code_id' => $otherClass->id]);
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    $row = collect((new MonthlyReportGenerator)->snapshot($f->date)['teachers'])->firstWhere('teacher_id', $f->teacher->id);

    expect($row['total_periods'])->toBe(1) // only the running class
        ->and($row['present_admin'])->toBe(0)
        ->and($row['pending'])->toBe(1); // no scans: the running class waits for a human
});

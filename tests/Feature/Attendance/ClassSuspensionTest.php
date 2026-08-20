<?php

use App\Enums\DayType;
use App\Enums\EmploymentType;
use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Models\CalendarDay;
use App\Models\PeriodResult;
use App\Services\Attendance\DayResolver;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use App\Services\Reporting\MonthlyReportGenerator;
use Tests\Support\AttendanceFixture;

/**
 * The two core suspension paths (in-scope -> PRESENT_ADMIN with no scan
 * needed, out-of-scope -> normal scan-based path) already have exact
 * coverage in DayResolverTest.php. This file covers what's left: the
 * whole-school (empty scope) case, a teacher who scanned anyway on a
 * suspended day, retro-marking an *already-computed* normal day as
 * suspended (not just a holiday), and whether PRESENT_ADMIN actually
 * pays — the question this whole feature exists to answer for a real
 * suspension day like a sequence exam.
 */
function suspensionResolver(): DayResolver
{
    $builder = new SessionBuilder;
    $writer = new PeriodResultWriter;

    return new DayResolver($builder, new PairingEngine, new RuleEngine($builder, $writer), $writer);
}

it('suspends every class when no class codes are attached to the calendar day (whole-school scope)', function () {
    $f = AttendanceFixture::make();
    // Empty scope, per CalendarDay::suspendsClassCode()'s "scoped
    // isEmpty() -> whole school" rule — no ->attach() call at all.
    CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    $result = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    expect($result->status)->toBe(PeriodStatus::PresentAdmin);
});

it('ignores a scan on a suspended day — a teacher who scanned anyway still gets PRESENT_ADMIN, not a paired scan result', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    // Scans in and out exactly as if it were a normal teaching day.
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    $result = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    // Administrative, not Scan — DayResolver skips pairing entirely
    // for an in-scope suspended session, so the scan is simply never
    // looked at, not "present because it happened to also pair."
    expect($result->status)->toBe(PeriodStatus::PresentAdmin);
    expect($result->source)->toBe(PeriodSource::Administrative);
    expect($result->session->state->value)->toBe('unpaired'); // pairing never ran
});

it('retro-marking an already-computed normal day as suspended flips it to PRESENT_ADMIN in place', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    $before = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    expect($before->status)->toBe(PeriodStatus::Present);

    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);
    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    // Same rule_version_id, so PeriodResultWriter updates the same row
    // in place rather than inserting a second one (see its own
    // docblock) — retro-marking a suspension isn't a rule change, it's
    // the same computation reflecting a corrected input.
    expect(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(1);
    $after = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    expect($after->id)->toBe($before->id);
    expect($after->status)->toBe(PeriodStatus::PresentAdmin);
});

it('PRESENT_ADMIN counts as taught in the monthly report, for both hourly and salaried teachers', function () {
    $f = AttendanceFixture::make();
    $f->teacher->update(['employment_type' => EmploymentType::Hourly]);
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);

    suspensionResolver()->resolve($f->teacher, $f->date, $f->rule);

    $snapshot = (new MonthlyReportGenerator)->snapshot($f->date);
    $row = collect($snapshot['teachers'])->firstWhere('teacher_id', $f->teacher->id);

    expect($row['present_admin'])->toBe(1);
    expect($row['hours_taught'])->toBe((float) $f->rule->hours_per_period);
    expect($snapshot['totals']['payable_hours'])->toBeGreaterThan(0.0);
});

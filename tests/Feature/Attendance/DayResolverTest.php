<?php

use App\Enums\DayType;
use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Models\CalendarDay;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Services\Attendance\DayResolver;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use Tests\Support\AttendanceFixture;

function resolver(): DayResolver
{
    $builder = new SessionBuilder;
    $writer = new PeriodResultWriter;

    return new DayResolver($builder, new PairingEngine, new RuleEngine($builder, $writer), $writer);
}

it('computes a normal teaching day (no calendar_days row) via scan pairing', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    resolver()->resolve($f->teacher, $f->date, $f->rule);

    $results = PeriodResult::current()->where('teacher_id', $f->teacher->id)->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->status)->toBe(PeriodStatus::Present);
    expect($results->first()->source)->toBe(PeriodSource::Scan);
});

it('produces no period_results on a public holiday', function () {
    $f = AttendanceFixture::make();
    CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::PublicHoliday]);

    resolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(0);
});

it('retro-marking a computed day as a holiday clears current results without deleting history', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    resolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::current()->count())->toBe(1);

    CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::PublicHoliday]);
    resolver()->resolve($f->teacher, $f->date, $f->rule);

    expect(PeriodResult::current()->count())->toBe(0);
    expect(PeriodResult::count())->toBe(1); // still there, just not current
});

it('applies PRESENT_ADMIN with no scan when the session class_code is in the suspension scope', function () {
    $f = AttendanceFixture::make();
    // no scans at all
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($f->classCode->id);

    resolver()->resolve($f->teacher, $f->date, $f->rule);

    $result = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    expect($result->status)->toBe(PeriodStatus::PresentAdmin);
    expect($result->source)->toBe(PeriodSource::Administrative);
});

it('applies the normal scan-based path when the session class_code is NOT in the suspension scope', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $otherClass = ClassCode::factory()->create();
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');
    $calendarDay = CalendarDay::create(['date' => $f->date->toDateString(), 'day_type' => DayType::ClassesSuspended]);
    $calendarDay->suspendedClassCodes()->attach($otherClass->id); // a different class than this teacher teaches

    resolver()->resolve($f->teacher, $f->date, $f->rule);

    $result = PeriodResult::current()->where('teacher_id', $f->teacher->id)->first();
    expect($result->status)->toBe(PeriodStatus::Present);
    expect($result->source)->toBe(PeriodSource::Scan);
});

<?php

use App\Enums\PeriodStatus;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use Tests\Support\AttendanceFixture;

function computeSession(AttendanceFixture $f, ?RuleVersion $rule = null): \Illuminate\Support\Collection
{
    $rule ??= $f->rule;
    $builder = new SessionBuilder;
    $session = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))->first()
        ->fresh(['firstSlot', 'lastSlot', 'teacher', 'classCode']);

    (new PairingEngine)->pair($session, $rule);
    $session->refresh()->load('scanInEvent', 'scanOutEvent', 'firstSlot', 'lastSlot', 'teacher', 'classCode');

    return (new RuleEngine($builder, new PeriodResultWriter))->computeForSession($session, $rule);
}

// §5 worked example 1: 07h30–10h15 (3 periods), scan-in 08h30 (scan-out
// at session end) -> P1 absent, P2 present, P3 present. 2 of 3.
it('reproduces worked example 1 exactly', function () {
    $f = AttendanceFixture::make(
        [['07:30:00', '08:25:00'], ['08:25:00', '09:20:00'], ['09:20:00', '10:15:00']],
        ['grace_late_minutes' => 10, 'grace_early_minutes' => 15, 'pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1],
    );
    $f->scanAt('08:30:00');
    $f->scanAt('10:15:00');

    $results = computeSession($f)->sortBy(fn ($r) => $r->slot->seq)->values();

    expect($results->pluck('status')->map->value->all())->toBe(['absent', 'present', 'present']);
});

// §5 worked example 2: 13h45–15h35 (2 periods), scan-out 14h30
// (scan-in at session start) -> P1 present, P2 absent. 1 of 2.
it('reproduces worked example 2 exactly', function () {
    $f = AttendanceFixture::make(
        [['13:45:00', '14:40:00'], ['14:40:00', '15:35:00']],
        ['grace_late_minutes' => 10, 'grace_early_minutes' => 15, 'pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1],
    );
    $f->scanAt('13:45:00');
    $f->scanAt('14:30:00');

    $results = computeSession($f)->sortBy(fn ($r) => $r->slot->seq)->values();

    expect($results->pluck('status')->map->value->all())->toBe(['present', 'absent']);
});

it('never evaluates an unpaired session against the covering rule — every slot is UNPAIRED, not ABSENT', function () {
    $f = AttendanceFixture::make();
    // no scans at all

    $results = computeSession($f);

    expect($results)->toHaveCount(1);
    expect($results->first()->status)->toBe(PeriodStatus::Unpaired);
});

it('writes LOCATION_MISMATCH status when that is the session anomaly', function () {
    config(['attendance.enforce_location' => true]); // §7.4: off by default in the pilot
    $f = AttendanceFixture::make();
    $f->scanAt('07:28:00', device: $f->wrongDevice);

    $results = computeSession($f);

    expect($results->first()->status)->toBe(PeriodStatus::LocationMismatch);

    config(['attendance.enforce_location' => false]);
});

it('is idempotent under the same rule_version: recomputing reuses the same row', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:20:00');

    $first = computeSession($f, $f->rule)->first();
    $second = computeSession($f, $f->rule)->first();

    expect(PeriodResult::count())->toBe(1);
    expect($second->id)->toBe($first->id);
});

it('preserves the old row when a new rule_version recomputes, flipping is_current without destroying it', function () {
    $f = AttendanceFixture::make(ruleOverrides: [
        'grace_late_minutes' => 10, 'grace_early_minutes' => 15,
        'pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1,
    ]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:20:00');

    $originalResult = computeSession($f, $f->rule)->first();
    expect($originalResult->status)->toBe(PeriodStatus::Present);

    // A stricter rule_version that this same pairing can no longer satisfy.
    $strictRule = RuleVersion::factory()->create([
        'grace_late_minutes' => 1, 'grace_early_minutes' => 1,
        'pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60,
        'min_scan_gap_seconds' => 30, 'min_session_minutes' => 1,
        'valid_from' => now()->toDateString(),
    ]);
    $newResult = computeSession($f, $strictRule)->first();

    expect(PeriodResult::count())->toBe(2);

    $oldRow = PeriodResult::find($originalResult->id);
    expect($oldRow->is_current)->toBeFalse();
    expect($oldRow->status)->toBe(PeriodStatus::Present); // untouched

    expect($newResult->is_current)->toBeTrue();
});

<?php

use App\Enums\EmploymentType;
use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Services\Reporting\MonthlyReportGenerator;

function generator(): MonthlyReportGenerator
{
    return new MonthlyReportGenerator;
}

it('sums present and absent periods per teacher for the given month only', function () {
    $teacher = Teacher::factory()->create(['full_name' => 'Ngwa Fon Peter', 'employment_type' => EmploymentType::Hourly]);
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);

    PeriodResult::factory()->count(3)->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDays(2)]);
    PeriodResult::factory()->count(2)->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Absent, 'date' => now()->startOfMonth()->addDays(3)]);
    // Outside the target month — must not be counted.
    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->subMonth()]);

    $snapshot = generator()->snapshot(now());

    $row = collect($snapshot['teachers'])->firstWhere('teacher_id', $teacher->id);

    expect($row['present'])->toBe(3);
    expect($row['absent'])->toBe(2);
    expect($row['total_periods'])->toBe(5);
    expect($row['hours_taught'])->toBe(3.0);
});

it('uses the override status, not the computed one, when a result was overridden', function () {
    $teacher = Teacher::factory()->create();
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);

    PeriodResult::factory()->create([
        'teacher_id' => $teacher->id,
        'rule_version_id' => $rule->id,
        'status' => PeriodStatus::Unpaired,
        'override_status' => PeriodStatus::Present,
        'date' => now()->startOfMonth()->addDay(),
    ]);

    $row = collect(generator()->snapshot(now())['teachers'])->firstWhere('teacher_id', $teacher->id);

    expect($row['present'])->toBe(1);
    expect($row['pending'])->toBe(0);
    expect($row['hours_taught'])->toBe(1.0);
});

it('sums hours per row using that row\'s own rule version, not a fixed rate', function () {
    $teacher = Teacher::factory()->create();
    $oldRule = RuleVersion::factory()->create(['hours_per_period' => 1.00, 'valid_from' => now()->subYear()]);
    $newRule = RuleVersion::factory()->create(['hours_per_period' => 1.50, 'valid_from' => now()->subDays(5)]);

    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $oldRule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);
    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $newRule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDays(2)]);

    $row = collect(generator()->snapshot(now())['teachers'])->firstWhere('teacher_id', $teacher->id);

    expect($row['hours_taught'])->toBe(2.5);
});

it('separates payable hours (hourly staff) from oversight hours (salaried staff)', function () {
    $hourly = Teacher::factory()->create(['employment_type' => EmploymentType::Hourly]);
    $salaried = Teacher::factory()->create(['employment_type' => EmploymentType::Salaried]);
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);

    PeriodResult::factory()->create(['teacher_id' => $hourly->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);
    PeriodResult::factory()->create(['teacher_id' => $salaried->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);

    $totals = generator()->snapshot(now())['totals'];

    expect($totals['payable_hours'])->toBe(1.0);
    expect($totals['oversight_hours'])->toBe(1.0);
});

it('flags pending exceptions at the report level', function () {
    $teacher = Teacher::factory()->create();
    $rule = RuleVersion::factory()->create();

    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Unpaired, 'date' => now()->startOfMonth()->addDay()]);

    $snapshot = generator()->snapshot(now());

    expect($snapshot['has_pending_exceptions'])->toBeTrue();
    expect($snapshot['totals']['pending'])->toBe(1);
});

it('creates a draft report on first generate', function () {
    Teacher::factory()->create();

    $report = generator()->generate(now());

    expect($report->state)->toBe(ReportState::Draft);
    expect($report->snapshot_json)->not->toBeNull();
    expect(MonthlyReport::count())->toBe(1);
});

it('regenerating a draft report refreshes its snapshot in place, not a new row', function () {
    $teacher = Teacher::factory()->create();
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);

    $report = generator()->generate(now());
    expect($report->snapshot_json['totals']['present'])->toBe(0);

    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);

    $regenerated = generator()->generate(now());

    expect(MonthlyReport::count())->toBe(1);
    expect($regenerated->id)->toBe($report->id);
    expect($regenerated->snapshot_json['totals']['present'])->toBe(1);
});

it('refuses to regenerate a report that has already been principal-approved', function () {
    $report = MonthlyReport::factory()->create([
        'month' => now()->startOfMonth(),
        'state' => ReportState::PrincipalApproved,
        'snapshot_json' => ['totals' => ['present' => 999]],
    ]);

    $result = generator()->generate(now());

    expect($result->id)->toBe($report->id);
    expect($result->snapshot_json['totals']['present'])->toBe(999);
});

// totals() reproduces snapshot()['totals'] as a single grouped query so
// the dashboard widget doesn't have to hydrate the month. Two
// implementations of one piece of arithmetic can drift apart silently,
// and the thing that would drift is the number payroll is read from —
// so pin them together over a dataset exercising every branch that
// differs between them: each status, both employment types, an override
// that changes the effective status, a pending status, a teacher with
// no results, and a mid-month hours_per_period change.
it('computes the same totals as a full snapshot, across every status and both employment types', function () {
    $month = now()->startOfMonth();

    $septemberRule = RuleVersion::factory()->create(['valid_from' => $month->copy()->toDateString(), 'hours_per_period' => 1.00]);
    $midMonthRule = RuleVersion::factory()->create(['valid_from' => $month->copy()->addDays(15)->toDateString(), 'hours_per_period' => 1.75]);

    $hourly = Teacher::factory()->create(['employment_type' => EmploymentType::Hourly]);
    $salaried = Teacher::factory()->create(['employment_type' => EmploymentType::Salaried]);
    Teacher::factory()->create(['employment_type' => EmploymentType::Hourly]);

    $make = function (Teacher $teacher, RuleVersion $rule, PeriodStatus $status, int $day, array $extra = []) use ($month) {
        return PeriodResult::factory()->create(array_merge([
            'teacher_id' => $teacher->id,
            'rule_version_id' => $rule->id,
            'status' => $status,
            'date' => $month->copy()->addDays($day),
        ], $extra));
    };

    foreach ([PeriodStatus::Present, PeriodStatus::PresentAdmin, PeriodStatus::Absent, PeriodStatus::AbsentJustified, PeriodStatus::Unpaired, PeriodStatus::LocationMismatch] as $i => $status) {
        $make($hourly, $septemberRule, $status, $i + 1);
        $make($salaried, $midMonthRule, $status, $i + 16);
    }

    // An override that flips a pending status into a taught one, and one
    // that flips a computed Present into an Absent.
    $make($hourly, $midMonthRule, PeriodStatus::Unpaired, 17, ['override_status' => PeriodStatus::Present]);
    $make($salaried, $septemberRule, PeriodStatus::Present, 4, ['override_status' => PeriodStatus::Absent]);

    // Superseded rows must be ignored by both paths.
    $make($hourly, $septemberRule, PeriodStatus::Present, 5, ['is_current' => false]);

    // Outside the month entirely.
    $make($hourly, $septemberRule, PeriodStatus::Present, -3);

    $totals = generator()->totals($month);

    // Guard against the comparison below passing vacuously on two empty
    // result sets — every bucket must actually have been exercised.
    expect($totals)->toBe([
        'present' => 3,
        'present_admin' => 2,
        'absent' => 3,
        'absent_justified' => 2,
        'pending' => 4,
        'payable_hours' => 3.75,
        'oversight_hours' => 3.5,
    ]);

    expect($totals)->toBe(generator()->snapshot($month)['totals']);
});

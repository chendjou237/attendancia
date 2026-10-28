<?php

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Models\AttendanceSession;
use App\Models\Corridor;
use App\Models\Device;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Services\Reporting\TeacherMonthDetail;
use Illuminate\Support\Carbon;

/**
 * The teacher PDF's day-by-day review (2026-10: teachers could not tell
 * how their monthly total was reached).
 */
function detailResult(Teacher $teacher, string $date, PeriodSlot $slot, PeriodStatus $status, array $overrides = []): PeriodResult
{
    return PeriodResult::factory()->create(array_merge([
        'teacher_id' => $teacher->id,
        'date' => $date,
        'slot_id' => $slot->id,
        'status' => $status,
        'source' => PeriodSource::Scan,
        'is_current' => true,
        'rule_version_id' => RuleVersion::factory()->create(['hours_per_period' => 1])->id,
    ], $overrides));
}

function detailScan(?int $teacherId, string $local, Device $device, int $subType = 38): RawEvent
{
    $at = Carbon::parse($local, config('attendance.timezone'))->utc();

    return RawEvent::factory()->create([
        'teacher_id' => $teacherId,
        'device_id' => $device->id,
        'device_serial' => $device->serial,
        'sub_event_type' => $subType,
        'event_time_device' => $at,
        'event_time_server' => $at,
    ]);
}

it('lists each day\'s periods, hours and scans, with day totals', function () {
    $teacher = Teacher::factory()->create();
    $device = Device::factory()->for(Corridor::factory()->create(['name' => 'couloir 1']))->create();
    $p1 = PeriodSlot::factory()->create(['seq' => 1, 'start_time' => '07:30:00', 'end_time' => '08:25:00']);
    $p2 = PeriodSlot::factory()->create(['seq' => 2, 'start_time' => '08:25:00', 'end_time' => '09:20:00']);
    $p3 = PeriodSlot::factory()->create(['seq' => 3, 'start_time' => '09:20:00', 'end_time' => '10:15:00']);
    detailResult($teacher, '2026-10-13', $p2, PeriodStatus::Absent);
    detailResult($teacher, '2026-10-13', $p1, PeriodStatus::Present);
    $session = AttendanceSession::factory()->create(['teacher_id' => $teacher->id, 'anomaly_code' => 'no_scan_out']);
    detailResult($teacher, '2026-10-13', $p3, PeriodStatus::Unpaired, ['session_id' => $session->id]);
    detailScan($teacher->id, '2026-10-13 07:20:59', $device);
    detailScan($teacher->id, '2026-10-13 07:25:00', $device, subType: 49); // failed read: not a scan
    detailResult($teacher, '2026-09-30', $p1, PeriodStatus::Present); // other month

    $detail = app(TeacherMonthDetail::class)->build($teacher, Carbon::parse('2026-10-01'));

    expect($detail['days'])->toHaveCount(1);
    $day = $detail['days'][0];
    expect(collect($day['periods'])->pluck('seq')->all())->toBe([1, 2, 3])
        ->and($day['present_periods'])->toBe(1)
        ->and($day['hours_present'])->toEqual(1.0)
        ->and($day['hours_absent'])->toEqual(1.0)
        ->and($day['pending'])->toBe(1)
        ->and($day['periods'][2]['why'])->not->toBeNull()
        ->and($day['scans'])->toBe([['time' => '07:20', 'place' => 'couloir 1']])
        ->and($detail['counts'])->toBe(['present' => 1, 'present_admin' => 0, 'absent' => 1, 'absent_justified' => 0, 'pending' => 1]);
});

it('shows a day with scans but no periods, and a merged duplicate\'s scans', function () {
    $teacher = Teacher::factory()->create();
    $duplicate = Teacher::factory()->create();
    $duplicate->forceFill(['merged_into_id' => $teacher->id])->save();
    $device = Device::factory()->for(Corridor::factory())->create();
    detailScan($duplicate->id, '2026-10-20 07:29:00', $device);

    $detail = app(TeacherMonthDetail::class)->build($teacher, Carbon::parse('2026-10-01'));

    expect($detail['days'])->toHaveCount(1)
        ->and($detail['days'][0]['periods'])->toBe([])
        ->and($detail['days'][0]['scans'][0]['time'])->toBe('07:29');
});

it('uses the effective status, so an override counts as what a human decided', function () {
    $teacher = Teacher::factory()->create();
    $slot = PeriodSlot::factory()->create();
    detailResult($teacher, '2026-10-13', $slot, PeriodStatus::Unpaired, ['override_status' => PeriodStatus::Present, 'override_reason' => 'Terminal en panne']);

    $period = app(TeacherMonthDetail::class)->build($teacher, Carbon::parse('2026-10-01'))['days'][0]['periods'][0];

    expect($period['status'])->toBe(PeriodStatus::Present)
        ->and($period['hours_present'])->toEqual(1.0)
        ->and($period['override_reason'])->toBe('Terminal en panne');
});

it('flags when the detail no longer adds up to a frozen summary', function () {
    $counts = ['present' => 10, 'present_admin' => 0, 'absent' => 2, 'absent_justified' => 0, 'pending' => 0];

    expect(TeacherMonthDetail::matchesSummary($counts, $counts + ['total_periods' => 12]))->toBeTrue()
        ->and(TeacherMonthDetail::matchesSummary($counts, ['present' => 9] + $counts))->toBeFalse();
});

it('prints the day blocks in the teacher PDF', function () {
    $teacher = Teacher::factory()->create(['staff_no' => 'T-92']);
    $device = Device::factory()->for(Corridor::factory()->create(['name' => 'couloir 2']))->create();
    $slot = PeriodSlot::factory()->create(['seq' => 1, 'start_time' => '07:30:00', 'end_time' => '08:25:00']);
    detailResult($teacher, '2026-10-13', $slot, PeriodStatus::Present);
    detailScan($teacher->id, '2026-10-13 07:28:00', $device);
    $report = MonthlyReport::factory()->create([
        'month' => '2026-10-01',
        'state' => ReportState::Draft,
        'snapshot_json' => ['generated_at' => now()->toIso8601String(), 'hours_basis' => 'x', 'totals' => [], 'has_pending_exceptions' => false, 'teachers' => []],
    ]);
    $row = ['teacher_id' => $teacher->id, 'staff_no' => 'T-92', 'full_name' => $teacher->full_name, 'employment_type' => 'hourly',
        'present' => 1, 'present_admin' => 0, 'absent' => 0, 'absent_justified' => 0, 'pending' => 0, 'total_periods' => 1, 'hours_taught' => 1];
    $detail = app(TeacherMonthDetail::class)->build($teacher, Carbon::parse('2026-10-01'));

    $html = view('pdf.teacher-monthly-report', [
        'report' => $report, 'row' => $row, 'snapshot' => $report->snapshot_json,
        'detail' => $detail, 'detailMatchesSummary' => TeacherMonthDetail::matchesSummary($detail['counts'], $row),
    ])->render();

    expect($html)->toContain(__('panel.resources.monthly_reports.day_detail'))
        ->toContain('13/10/2026')
        ->toContain('07:28 (couloir 2)')
        ->toContain(__('panel.resources.monthly_reports.day_present_total'))
        ->toContain(trans_choice('panel.resources.monthly_reports.present_periods', 1, ['count' => 1]))
        ->not->toContain(__('panel.resources.monthly_reports.detail_differs', ['date' => '']));
});

<?php

namespace App\Services\Reporting;

use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * §9/§14: "1 period = 1 hour" is the *current* basis, not a constant —
 * hours_per_period lives on rule_versions and can change. Each period
 * result already carries the rule_version_id that governed it, so hours
 * are summed per-row from that row's own rule version rather than one
 * rate applied to the whole month. A mid-month rule change is therefore
 * reflected correctly without any special-casing here.
 *
 * One report per calendar month (monthly_reports.month is unique),
 * covering every teacher — not one row per teacher — matching §10's
 * "per-teacher headline with per-period detail underneath" shape.
 */
class MonthlyReportGenerator
{
    /**
     * Pure computation — no writes. Used both by generate() and by the
     * dashboard, which wants live current-month numbers without
     * creating or mutating a monthly_reports row for a month nobody
     * has asked to close out yet.
     */
    public function snapshot(CarbonInterface $month): array
    {
        $start = $month->clone()->startOfMonth();
        $end = $month->clone()->endOfMonth();

        // whereDate() — `date` is a `date` cast, which serialises to
        // "Y-m-d H:i:s" on save; a plain whereBetween on the raw column
        // works by coincidence on MySQL's real DATE type but not on a
        // DB that stores dates as text. See PeriodResult::casts() and
        // the identical trap documented throughout the pairing/rule
        // engine.
        $results = PeriodResult::query()
            ->current()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->with(['teacher', 'ruleVersion'])
            ->get();

        $teacherRows = $results
            ->groupBy('teacher_id')
            ->filter(fn (Collection $rows) => $rows->first()->teacher !== null)
            ->map(fn (Collection $rows) => $this->summarizeTeacher($rows))
            ->sortBy('full_name')
            ->values();

        $pendingTotal = (int) $teacherRows->sum('pending');

        return [
            'month' => $start->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'hours_basis' => 'Each taught period (Present or Present (administrative)) counts as that period\'s rule_version.hours_per_period at the time it was computed. A later rule change only affects periods computed after the change.',
            'teachers' => $teacherRows->all(),
            'totals' => [
                'present' => (int) $teacherRows->sum('present'),
                'present_admin' => (int) $teacherRows->sum('present_admin'),
                'absent' => (int) $teacherRows->sum('absent'),
                'absent_justified' => (int) $teacherRows->sum('absent_justified'),
                'pending' => $pendingTotal,
                'payable_hours' => round((float) $teacherRows->where('employment_type', 'hourly')->sum('hours_taught'), 2),
                'oversight_hours' => round((float) $teacherRows->where('employment_type', 'salaried')->sum('hours_taught'), 2),
            ],
            'has_pending_exceptions' => $pendingTotal > 0,
        ];
    }

    /**
     * Creates or refreshes the Draft (or still-under-review) report for
     * a month. A report already Principal-approved or sent to HR is
     * frozen — §3's "a November edit never rewrites September's pay"
     * applies here just as it does to rule_versions and timetables, so
     * this refuses to touch one rather than silently overwriting the
     * record HR already received.
     */
    public function generate(CarbonInterface $month): MonthlyReport
    {
        $start = $month->clone()->startOfMonth();

        $report = MonthlyReport::query()->whereDate('month', $start->toDateString())->first();

        if ($report !== null && ! in_array($report->state, [ReportState::Draft, ReportState::OfficerReviewed], true)) {
            return $report;
        }

        $snapshot = $this->snapshot($start);

        if ($report === null) {
            return MonthlyReport::create([
                'month' => $start->toDateString(),
                'state' => ReportState::Draft,
                'snapshot_json' => $snapshot,
                'generated_at' => now(),
            ]);
        }

        $report->update([
            'snapshot_json' => $snapshot,
            'generated_at' => now(),
        ]);

        return $report;
    }

    private function summarizeTeacher(Collection $rows): array
    {
        $teacher = $rows->first()->teacher;

        $effectiveStatuses = $rows->map(fn (PeriodResult $r) => $r->effectiveStatus());

        $hoursTaught = $rows
            ->filter(fn (PeriodResult $r) => $r->effectiveStatus()->countsAsTaught())
            ->sum(fn (PeriodResult $r) => (float) $r->ruleVersion->hours_per_period);

        return [
            'teacher_id' => $teacher->id,
            'staff_no' => $teacher->staff_no,
            'full_name' => $teacher->full_name,
            'employment_type' => $teacher->employment_type->value,
            'present' => $effectiveStatuses->filter(fn (PeriodStatus $s) => $s === PeriodStatus::Present)->count(),
            'present_admin' => $effectiveStatuses->filter(fn (PeriodStatus $s) => $s === PeriodStatus::PresentAdmin)->count(),
            'absent' => $effectiveStatuses->filter(fn (PeriodStatus $s) => $s === PeriodStatus::Absent)->count(),
            'absent_justified' => $effectiveStatuses->filter(fn (PeriodStatus $s) => $s === PeriodStatus::AbsentJustified)->count(),
            'pending' => $effectiveStatuses->filter(fn (PeriodStatus $s) => $s->isPending())->count(),
            'total_periods' => $rows->count(),
            'hours_taught' => round($hoursTaught, 2),
        ];
    }
}

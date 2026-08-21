<?php

namespace App\Services\Reporting;

use App\Enums\EmploymentType;
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

        // Plain range comparisons on the raw column, which is what lets
        // MySQL use period_results_current_lookup here — this is the
        // widest scan in the app. Safe because App\Casts\DateOnly stores
        // `date` as a bare "Y-m-d" on every engine.
        $results = PeriodResult::query()
            ->current()
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
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
     * Just the `totals` block of a snapshot, computed as one grouped
     * query instead of by hydrating the month's period_results.
     *
     * The dashboard widget polls this on a timer and only ever renders
     * the totals — running the full snapshot() for that meant loading
     * every result row for the month with its teacher and rule version
     * attached, on a machine that is also running the stream worker and
     * MySQL. Grouping by (employment_type, effective status) collapses
     * the same arithmetic to a handful of rows, and the date range stays
     * a plain comparison so period_results_current_lookup is usable.
     *
     * Deliberately a second implementation rather than a refactor of
     * snapshot(): report generation needs the per-teacher rows and must
     * not change shape for a dashboard optimisation. The two are pinned
     * together by a test asserting this equals snapshot()['totals'], so
     * they cannot drift silently.
     *
     * @return array{present: int, present_admin: int, absent: int, absent_justified: int, pending: int, payable_hours: float, oversight_hours: float}
     */
    public function totals(CarbonInterface $month): array
    {
        $start = $month->clone()->startOfMonth();
        $end = $month->clone()->endOfMonth();

        // Inner joins, matching snapshot()'s exclusion of rows whose
        // teacher no longer resolves.
        $rows = PeriodResult::query()
            ->current()
            ->where('period_results.date', '>=', $start->toDateString())
            ->where('period_results.date', '<=', $end->toDateString())
            ->join('teachers', 'teachers.id', '=', 'period_results.teacher_id')
            ->join('rule_versions', 'rule_versions.id', '=', 'period_results.rule_version_id')
            ->groupBy('teachers.employment_type', 'effective_status')
            ->selectRaw('teachers.employment_type as employment_type')
            ->selectRaw('coalesce(period_results.override_status, period_results.status) as effective_status')
            ->selectRaw('count(*) as tally')
            ->selectRaw('sum(rule_versions.hours_per_period) as hours')
            ->get();

        $counts = [
            PeriodStatus::Present->value => 0,
            PeriodStatus::PresentAdmin->value => 0,
            PeriodStatus::Absent->value => 0,
            PeriodStatus::AbsentJustified->value => 0,
        ];
        $pending = 0;
        $payableHours = 0.0;
        $oversightHours = 0.0;

        foreach ($rows as $row) {
            $status = PeriodStatus::from($row->effective_status);
            $tally = (int) $row->tally;

            if ($status->isPending()) {
                $pending += $tally;
            } else {
                $counts[$status->value] += $tally;
            }

            if (! $status->countsAsTaught()) {
                continue;
            }

            if ($row->employment_type === EmploymentType::Hourly->value) {
                $payableHours += (float) $row->hours;
            } elseif ($row->employment_type === EmploymentType::Salaried->value) {
                $oversightHours += (float) $row->hours;
            }
        }

        return [
            'present' => $counts[PeriodStatus::Present->value],
            'present_admin' => $counts[PeriodStatus::PresentAdmin->value],
            'absent' => $counts[PeriodStatus::Absent->value],
            'absent_justified' => $counts[PeriodStatus::AbsentJustified->value],
            'pending' => $pending,
            'payable_hours' => round($payableHours, 2),
            'oversight_hours' => round($oversightHours, 2),
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

        $report = MonthlyReport::query()->where('month', $start->toDateString())->first();

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

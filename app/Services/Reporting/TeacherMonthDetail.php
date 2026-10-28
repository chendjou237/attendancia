<?php

namespace App\Services\Reporting;

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Models\PeriodResult;
use App\Models\RawEvent;
use App\Models\Teacher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One teacher's month, day by day: every period with what it counted as,
 * and the scans that produced it — so a teacher reading their PDF can
 * check each figure against their own memory of the day instead of
 * taking a single monthly total on trust (the complaint that prompted
 * this, 2026-10).
 *
 * Read-only, from current period_results and raw_events. The monthly
 * summary stays the report's snapshot; this is the evidence under it.
 */
class TeacherMonthDetail
{
    /**
     * @return array{
     *     days: list<array{date: Carbon, periods: list<array<string, mixed>>, scans: list<array{time: string, place: string}>, present_periods: int, hours_present: float, hours_absent: float, pending: int}>,
     *     counts: array{present: int, present_admin: int, absent: int, absent_justified: int, pending: int}
     * }
     */
    public function build(Teacher $teacher, CarbonInterface $month): array
    {
        $tz = config('attendance.timezone');
        $first = Carbon::parse($month->format('Y-m-01'), $tz)->startOfDay();
        $next = $first->clone()->addMonth();

        $results = PeriodResult::query()
            ->current()
            ->where('teacher_id', $teacher->id)
            ->where('date', '>=', $first->toDateString())
            ->where('date', '<', $next->toDateString())
            ->with(['slot', 'classCode', 'ruleVersion', 'session'])
            ->get()
            ->groupBy(fn (PeriodResult $r) => $r->date->toDateString());

        $scans = $this->scans($teacher, $first, $next, $tz);

        $dates = $results->keys()->merge($scans->keys())->unique()->sort()->values();

        $counts = ['present' => 0, 'present_admin' => 0, 'absent' => 0, 'absent_justified' => 0, 'pending' => 0];

        $days = $dates->map(function (string $date) use ($results, $scans, &$counts, $tz) {
            $periods = ($results->get($date) ?? collect())
                ->sortBy(fn (PeriodResult $r) => $r->slot?->seq)
                ->values()
                ->map(function (PeriodResult $r) use (&$counts) {
                    $status = $r->effectiveStatus();
                    $hours = (float) $r->ruleVersion?->hours_per_period;

                    $key = $status->isPending() ? 'pending' : $status->value;
                    if (array_key_exists($key, $counts)) {
                        $counts[$key]++;
                    }

                    return [
                        'seq' => $r->slot?->seq,
                        'time' => $r->slot ? substr($r->slot->start_time, 0, 5).'–'.substr($r->slot->end_time, 0, 5) : '—',
                        'class' => $r->classCode?->code ?? '—',
                        'status' => $status,
                        // Why it is pending, in the panel's own words.
                        'why' => $status->isPending() && $r->session?->anomaly_code
                            ? (SessionAnomaly::tryFrom($r->session->anomaly_code)?->getLabel() ?? $r->session->anomaly_code)
                            : null,
                        'override_reason' => $r->override_status !== null ? $r->override_reason : null,
                        'hours_present' => $status->countsAsTaught() ? $hours : 0.0,
                        'hours_absent' => in_array($status, [PeriodStatus::Absent, PeriodStatus::AbsentJustified], true) ? $hours : 0.0,
                    ];
                });

            return [
                'date' => Carbon::parse($date, $tz),
                'periods' => $periods->all(),
                'scans' => ($scans->get($date) ?? collect())->all(),
                'present_periods' => $periods->filter(fn (array $p) => $p['status']->countsAsTaught())->count(),
                'hours_present' => round($periods->sum('hours_present'), 2),
                'hours_absent' => round($periods->sum('hours_absent'), 2),
                'pending' => $periods->filter(fn (array $p) => $p['status']->isPending())->count(),
            ];
        })->values()->all();

        return ['days' => $days, 'counts' => $counts];
    }

    /**
     * Whether the per-day detail adds up to the report's summary row. It
     * won't for a frozen report whose periods were overridden after it
     * was generated — the PDF says so rather than showing two totals that
     * silently disagree.
     *
     * @param  array<string, int>  $counts  from build()
     * @param  array<string, mixed>  $summaryRow  the teacher's row in the report snapshot
     */
    public static function matchesSummary(array $counts, array $summaryRow): bool
    {
        foreach ($counts as $key => $value) {
            if ((int) ($summaryRow[$key] ?? 0) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fingerprint-passed scans only (sub-type 38 — the only kind that
     * counts, see EventProcessor), grouped by school-local date. Includes
     * a merged duplicate's scans, as pairing does.
     *
     * @return Collection<string, Collection<int, array{time: string, place: string}>>
     */
    private function scans(Teacher $teacher, Carbon $first, Carbon $next, string $tz): Collection
    {
        $from = $first->clone()->utc();
        $to = $next->clone()->utc();

        return RawEvent::query()
            ->whereIn('teacher_id', $teacher->scanSourceIds())
            ->where('sub_event_type', 38)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('event_time_device', '>=', $from)->where('event_time_device', '<', $to))
                ->orWhere(fn ($q) => $q->whereNull('event_time_device')
                    ->where('event_time_server', '>=', $from)->where('event_time_server', '<', $to)))
            ->with('device.corridor')
            ->get()
            ->sortBy(fn (RawEvent $e) => $e->effectiveTime())
            ->map(fn (RawEvent $e) => [
                'local' => $e->effectiveTime()->clone()->setTimezone($tz),
                'place' => $e->device?->corridor?->name ?? $e->device_serial,
            ])
            ->groupBy(fn (array $s) => $s['local']->toDateString())
            ->map(fn (Collection $day) => $day->map(fn (array $s) => [
                'time' => $s['local']->format('H:i'),
                'place' => $s['place'],
            ])->values());
    }
}

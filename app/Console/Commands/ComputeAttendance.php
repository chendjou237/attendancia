<?php

namespace App\Console\Commands;

use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Services\Attendance\DayResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The orchestrator (§8, §12 Phase A): resolves one date — or a range of
 * dates via --from/--to — for every active teacher, or a specific one,
 * through DayResolver.
 *
 * The range exists for recovery: nothing recomputes a date once it has
 * fallen out of NightlyRecovery's two-day window, so a stretch of days
 * whose scans were never turned into period_results (a stopped
 * scheduler, a device offline through a backfill window) is caught up
 * with one command rather than one invocation per day.
 *
 * Idempotent by construction, not by anything this command does itself:
 * every service in the pipeline (SessionBuilder::persist, PairingEngine,
 * PeriodResultWriter) upserts on a natural key, so running this twice
 * for a date whose inputs haven't changed reproduces the identical rows
 * — see each service's own docblock for its specific key.
 */
class ComputeAttendance extends Command
{
    protected $signature = 'attendance:compute
        {date? : Y-m-d, defaults to today}
        {--from= : Y-m-d, start of a date range to recompute}
        {--to= : Y-m-d, end of the range; defaults to today}
        {--teacher=* : staff_no of specific teacher(s); defaults to all active}';

    protected $description = 'Compute period attendance for one date, or a range of dates, across active teachers.';

    public function handle(DayResolver $resolver): int
    {
        $dates = $this->dates();

        if ($dates === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($dates as $date) {
            $failed += $this->computeDate($resolver, $date);
        }

        if ($dates->count() > 1) {
            $this->info("Range complete: {$dates->count()} date(s), {$failed} teacher-day(s) failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The dates this run covers, or null when the options don't make a
     * range. --from/--to is the recovery path for a backlog (a stretch
     * of days nothing ever computed); the positional argument stays the
     * everyday single-date form.
     *
     * @return Collection<int, Carbon>|null
     */
    private function dates(): ?Collection
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from === null && $to === null) {
            return collect([
                $this->argument('date') ? Carbon::parse($this->argument('date')) : Carbon::today(),
            ]);
        }

        if ($from === null) {
            $this->error('--to needs --from; pass a single date as the argument to compute one day.');

            return null;
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = $to !== null ? Carbon::parse($to)->startOfDay() : Carbon::today();

        if ($end->lessThan($start)) {
            $this->error("--to ({$end->toDateString()}) is before --from ({$start->toDateString()}).");

            return null;
        }

        $dates = collect();

        for ($date = $start->clone(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dates->push($date->clone());
        }

        return $dates;
    }

    /**
     * One date, resolved against the rule version covering *that* date
     * — not the range's: a range can straddle a rule change, and a day
     * must always be computed under the rule that was in force on it.
     *
     * @return int teacher-days that threw
     */
    private function computeDate(DayResolver $resolver, Carbon $date): int
    {
        $rule = RuleVersion::forDate($date);

        if ($rule === null) {
            $this->error("No rule_version is active on or before {$date->toDateString()}.");

            return 1;
        }

        $staffNos = $this->option('teacher');

        $teachers = Teacher::query()
            ->when($staffNos, fn ($q) => $q->whereIn('staff_no', $staffNos))
            ->when(! $staffNos, fn ($q) => $q
                ->where('active_from', '<=', $date->toDateString())
                ->where(fn ($q) => $q->whereNull('active_to')->orWhere('active_to', '>=', $date->toDateString())))
            ->get();

        if ($teachers->isEmpty()) {
            $this->warn("No matching active teachers for {$date->toDateString()}.");

            return 0;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($teachers as $teacher) {
            try {
                $resolver->resolve($teacher, $date, $rule);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Teacher {$teacher->staff_no}: {$e->getMessage()}");
            }
        }

        $this->info("Computed {$date->toDateString()}: {$succeeded} succeeded, {$failed} failed, rule_version #{$rule->id}.");

        return $failed;
    }
}

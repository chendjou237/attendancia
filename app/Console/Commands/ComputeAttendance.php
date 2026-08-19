<?php

namespace App\Console\Commands;

use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Services\Attendance\DayResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * The orchestrator (§8, §12 Phase A): resolves one date for every active
 * teacher, or a specific one, through DayResolver.
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
        {--teacher=* : staff_no of specific teacher(s); defaults to all active}';

    protected $description = 'Compute period attendance for one date across active teachers.';

    public function handle(DayResolver $resolver): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : Carbon::today();

        $rule = RuleVersion::forDate($date);

        if ($rule === null) {
            $this->error("No rule_version is active on or before {$date->toDateString()}.");

            return self::FAILURE;
        }

        $staffNos = $this->option('teacher');

        $teachers = Teacher::query()
            ->when($staffNos, fn ($q) => $q->whereIn('staff_no', $staffNos))
            ->when(! $staffNos, fn ($q) => $q
                ->where('active_from', '<=', $date->toDateString())
                ->where(fn ($q) => $q->whereNull('active_to')->orWhere('active_to', '>=', $date->toDateString())))
            ->get();

        if ($teachers->isEmpty()) {
            $this->warn('No matching active teachers for this date.');

            return self::SUCCESS;
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

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

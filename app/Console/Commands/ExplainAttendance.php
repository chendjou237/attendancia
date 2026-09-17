<?php

namespace App\Console\Commands;

use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Services\Attendance\SessionBuilder;
use App\Services\Attendance\SessionGrouping;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Read-only: answers "he scanned, so why does it say no scan-in?"
 * without a database console.
 *
 * Prints, for one teacher and date, the sessions the timetable expects,
 * each session's pairing window in BOTH school wall clock and UTC, every
 * raw_event for that teacher inside the window, and the stored result.
 * Writes nothing — no sessions are persisted, no period_results are
 * touched — so it is safe to run against production while a dispute is
 * being discussed.
 *
 * The window arithmetic deliberately repeats PairingEngine's own
 * conversion (Carbon::parse("$date $wallClock", $tz)->utc()) rather than
 * calling into it: the point of this command is to show the numbers the
 * engine works with, including the timezone step that is the usual
 * source of confusion — raw_events are UTC, period slots are local.
 */
class ExplainAttendance extends Command
{
    protected $signature = 'attendance:explain
        {date : Y-m-d}
        {--teacher= : staff_no of the teacher to explain}';

    protected $description = 'Explain, without writing anything, how one teacher\'s day pairs (or fails to).';

    public function handle(SessionBuilder $builder): int
    {
        $date = Carbon::parse($this->argument('date'))->startOfDay();
        $staffNo = $this->option('teacher');

        if ($staffNo === null) {
            $this->error('--teacher=<staff_no> is required.');

            return self::FAILURE;
        }

        $teacher = Teacher::query()->where('staff_no', $staffNo)->first();

        if ($teacher === null) {
            $this->error("No teacher with staff_no {$staffNo}.");

            return self::FAILURE;
        }

        $tz = config('attendance.timezone');
        $rule = RuleVersion::forDate($date);

        $this->line("Teacher : {$teacher->full_name} ({$teacher->staff_no})");
        $this->line("Date    : {$date->toDateString()} ({$date->translatedFormat('l')})");
        $this->line('Timezone: '.$tz.' — period slots are wall clock, raw_events are UTC');

        if ($rule === null) {
            $this->error("No rule_version is active on or before {$date->toDateString()} — attendance:compute would refuse this date.");

            return self::FAILURE;
        }

        $this->line("Rule    : #{$rule->id} (pair window -{$rule->pair_window_before_minutes}/+{$rule->pair_window_after_minutes} min, "
            ."grace late {$rule->grace_late_minutes} / early {$rule->grace_early_minutes} min, min session {$rule->min_session_minutes} min)");

        $version = $teacher->timetableVersionFor($date);
        $this->line('Timetable: '.($version === null
            ? 'NONE covering this date — no sessions can be built'
            : "version #{$version->id} from {$version->valid_from->toDateString()}"
                .($version->valid_to !== null ? " to {$version->valid_to->toDateString()}" : ' (open)')
                ." [{$version->state->value}]"));

        $this->newLine();
        $this->scansForDay($teacher, $date, $tz);

        $groupings = $builder->group($teacher, $date);

        if ($groupings->isEmpty()) {
            $this->newLine();
            $this->warn('No sessions expected on this date (no timetable entries for this day of week).');

            return self::SUCCESS;
        }

        foreach ($groupings as $grouping) {
            $this->newLine();
            $this->explainSession($teacher, $date, $grouping, $rule, $tz);
        }

        return self::SUCCESS;
    }

    /**
     * Every event the device recorded for this teacher that day, before
     * any window is applied — so "there is no scan at all" and "the scan
     * fell outside every window" are visibly different answers.
     */
    private function scansForDay(Teacher $teacher, Carbon $date, string $tz): void
    {
        $dayStart = Carbon::parse($date->toDateString().' 00:00:00', $tz)->utc();
        $dayEnd = $dayStart->clone()->addDay();

        $events = RawEvent::query()
            ->where('teacher_id', $teacher->id)
            ->where(function ($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('event_time_device', [$dayStart, $dayEnd])
                    ->orWhere(fn ($q2) => $q2->whereNull('event_time_device')
                        ->whereBetween('event_time_server', [$dayStart, $dayEnd]));
            })
            ->with('device')
            ->get()
            ->sortBy(fn (RawEvent $e) => $e->effectiveTime())
            ->values();

        $this->line("Scans resolved to this teacher on {$date->toDateString()}: {$events->count()}");

        if ($events->isEmpty()) {
            $this->warn('  None. Either nobody scanned, or the scan never resolved to this teacher '
                .'(only sub_event_type 38 — fingerprint passed — ever does; a card or a failed read never will).');

            return;
        }

        $this->table(
            ['raw_event', 'local', 'UTC', 'device', 'sub_type', 'source'],
            $events->map(fn (RawEvent $e) => [
                $e->id,
                $e->effectiveTime()->clone()->setTimezone($tz)->format('H:i:s'),
                $e->effectiveTime()->format('H:i:s'),
                $e->device?->serial ?? '—',
                $e->sub_event_type,
                $e->event_time_device !== null ? 'device clock' : 'server clock (device time missing)',
            ])->all(),
        );
    }

    private function explainSession(Teacher $teacher, Carbon $date, SessionGrouping $grouping, RuleVersion $rule, string $tz): void
    {
        $dateString = $date->toDateString();
        $sessionStart = Carbon::parse($dateString.' '.$grouping->firstSlot->start_time, $tz)->utc();
        $sessionEnd = Carbon::parse($dateString.' '.$grouping->lastSlot->end_time, $tz)->utc();
        $windowStart = $sessionStart->clone()->subMinutes($rule->pair_window_before_minutes);
        $windowEnd = $sessionEnd->clone()->addMinutes($rule->pair_window_after_minutes);

        $classCode = ClassCode::find($grouping->classCodeId);

        $this->line('Session: '.($classCode?->code ?? '?').' — periods '.$grouping->firstSlot->seq.'–'.$grouping->lastSlot->seq
            .' '.$grouping->firstSlot->start_time.'–'.$grouping->lastSlot->end_time.' (local)');
        $this->line('  scan-in accepted  '.$this->both($windowStart, $tz).' .. '.$this->both($sessionEnd, $tz));
        $this->line('  scan-out accepted  from the scan-in .. '.$this->both($windowEnd, $tz));

        $candidates = RawEvent::query()
            ->where('teacher_id', $teacher->id)
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('event_time_device', [$windowStart, $windowEnd])
                    ->orWhere(fn ($q2) => $q2->whereNull('event_time_device')
                        ->whereBetween('event_time_server', [$windowStart, $windowEnd]));
            })
            ->get()
            ->sortBy(fn (RawEvent $e) => $e->effectiveTime())
            ->values();

        if ($candidates->isEmpty()) {
            $this->warn('  No scan falls in this window -> UNPAIRED (no_scan_in).');
        } else {
            $times = $candidates
                ->map(fn (RawEvent $e) => '#'.$e->id.' '.$e->effectiveTime()->clone()->setTimezone($tz)->format('H:i:s'))
                ->implode(', ');
            $this->line('  Candidates (before debounce of '.$rule->min_scan_gap_seconds.'s): '.$times);
        }

        $stored = PeriodResult::query()
            ->current()
            ->where('teacher_id', $teacher->id)
            ->where('date', $dateString)
            ->whereIn('slot_id', $grouping->periodSlotIds)
            ->with('session')
            ->get();

        if ($stored->isEmpty()) {
            $this->warn('  Stored result: none — attendance:compute has never run for this teacher and date.');

            return;
        }

        foreach ($stored as $result) {
            $this->line('  Period '.$result->slot->seq.': '.$result->effectiveStatus()->value
                .($result->override_status !== null ? ' (overridden by a human)' : '')
                .($result->session?->anomaly_code !== null ? ' — '.$result->session->anomaly_code : '')
                .', computed '.$result->computed_at?->clone()->setTimezone($tz)->format('Y-m-d H:i:s'));
        }
    }

    private function both(Carbon $utc, string $tz): string
    {
        return $utc->clone()->setTimezone($tz)->format('H:i:s').' local ('.$utc->format('H:i:s').' UTC)';
    }
}

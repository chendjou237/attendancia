<?php

namespace App\Services\Attendance;

use App\Models\AuditLog;
use App\Models\PeriodResult;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of teachers.active_to-as-a-decision and of
 * teachers.merged_into_id. Each operation changes an input to the
 * pipeline, so each recomputes every date it touched before returning —
 * a stale period_result is invisible to the people reading the panel, and
 * the scheduled passes only look back seven days.
 *
 * Nothing is deleted or rewritten: results are retired through
 * DayResolver (is_current = false, as for a closure), raw_events keep
 * their teacher_id, and fingerprint mappings are superseded through
 * TeacherBiometricIdAssigner.
 *
 * Refusals throw InvalidArgumentException with a translated message the
 * panel shows as-is.
 */
class TeacherDeactivator
{
    public function __construct(
        private readonly DayResolver $resolver,
        private readonly TeacherBiometricIdAssigner $assigner,
    ) {}

    /**
     * The teacher stopped working here: nothing is expected of them after
     * $lastDay. Results up to and including $lastDay stand.
     *
     * @return int days recomputed
     */
    public function deactivate(Teacher $teacher, Carbon $lastDay): int
    {
        if ($teacher->merged_into_id !== null) {
            throw new InvalidArgumentException(__('panel.resources.teachers.error_already_merged', ['name' => $teacher->mergedInto->full_name]));
        }

        if ($lastDay->toDateString() < $teacher->active_from->toDateString()) {
            throw new InvalidArgumentException(__('panel.resources.teachers.error_last_day_before_start', ['date' => $teacher->active_from->toDateString()]));
        }

        return DB::transaction(function () use ($teacher, $lastDay): int {
            $before = $this->snapshot($teacher);

            $teacher->forceFill(['active_to' => $lastDay->toDateString()])->save();

            AuditLog::record('teachers', $teacher->id, 'deactivate', $before, $this->snapshot($teacher));

            return $this->recompute($teacher, $this->resultDates($teacher)->filter(fn (string $d) => $d > $lastDay->toDateString()));
        });
    }

    /**
     * $duplicate was created by mistake for the same person as $keeper. It
     * is deactivated from its first day, so nothing is ever expected of
     * it, and its scans — past and, through the moved fingerprint, future
     * — count for $keeper.
     *
     * @return int teacher-days recomputed, both records together
     */
    public function mergeInto(Teacher $duplicate, Teacher $keeper): int
    {
        if ($duplicate->is($keeper)) {
            throw new InvalidArgumentException(__('panel.resources.teachers.error_merge_self'));
        }

        if ($duplicate->merged_into_id !== null) {
            throw new InvalidArgumentException(__('panel.resources.teachers.error_already_merged', ['name' => $duplicate->mergedInto->full_name]));
        }

        // One level only, so scanSourceIds() never has to walk a chain.
        if ($keeper->merged_into_id !== null) {
            throw new InvalidArgumentException(__('panel.resources.teachers.error_keeper_is_duplicate', ['name' => $keeper->full_name]));
        }

        return DB::transaction(function () use ($duplicate, $keeper): int {
            $before = $this->snapshot($duplicate);

            // Read before anything moves: the duplicate's own scans and
            // those of anything already merged into it.
            $sourceIds = $duplicate->scanSourceIds();
            $scanDates = $this->scanDates($sourceIds);
            $duplicateResultDates = $this->resultDates($duplicate);

            $duplicate->forceFill([
                'active_to' => $duplicate->active_from->clone()->subDay()->toDateString(),
                'merged_into_id' => $keeper->id,
            ])->save();

            Teacher::query()->where('merged_into_id', $duplicate->id)->update(['merged_into_id' => $keeper->id]);

            $moved = $this->moveOpenBiometricIds($duplicate, $keeper);

            AuditLog::record('teachers', $duplicate->id, 'merge', $before, $this->snapshot($duplicate) + ['biometric_ids_moved' => $moved]);

            return $this->recompute($duplicate, $duplicateResultDates)
                + $this->recompute($keeper, $scanDates->merge($duplicateResultDates)->filter(
                    fn (string $d) => $keeper->isActiveOn(Carbon::parse($d)),
                ));
        });
    }

    /**
     * Undoes deactivate() or mergeInto(). A moved fingerprint stays with
     * the teacher it was moved to — re-enrol it from Teacher Biometric IDs
     * if it really belongs here.
     *
     * @return int teacher-days recomputed
     */
    public function reactivate(Teacher $teacher): int
    {
        return DB::transaction(function () use ($teacher): int {
            $before = $this->snapshot($teacher);
            $formerKeeper = $teacher->mergedInto;
            $scanDates = $this->scanDates([$teacher->id]);
            // Non-current rows too: those are the days deactivation retired.
            $retiredDates = PeriodResult::query()
                ->where('teacher_id', $teacher->id)
                ->distinct()
                ->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString());

            $teacher->forceFill(['active_to' => null, 'merged_into_id' => null])->save();

            AuditLog::record('teachers', $teacher->id, 'reactivate', $before, $this->snapshot($teacher));

            $days = $this->recompute($teacher, $retiredDates->merge($scanDates));

            if ($formerKeeper !== null) {
                $days += $this->recompute($formerKeeper, $scanDates->filter(
                    fn (string $d) => $formerKeeper->isActiveOn(Carbon::parse($d)),
                ));
            }

            return $days;
        });
    }

    /**
     * @return list<string>
     */
    private function moveOpenBiometricIds(Teacher $from, Teacher $to): array
    {
        $moved = [];

        foreach ($from->biometricIds()->whereNull('valid_to')->get() as $mapping) {
            /** @var TeacherBiometricId $mapping */
            // From the mapping's own start: the fingerprint was always the
            // keeper's. The duplicate's row is closed the day before, which
            // leaves it covering no day at all — accurate, and kept rather
            // than deleted so the history still shows where it was.
            $this->assigner->assign($to, $mapping->biometric_id, $mapping->valid_from);
            $moved[] = $mapping->biometric_id;
        }

        return $moved;
    }

    /**
     * @param  Collection<int, string>  $dates  Y-m-d
     */
    private function recompute(Teacher $teacher, Collection $dates): int
    {
        $today = now(config('attendance.timezone'))->toDateString();
        $teacher->refresh();
        $count = 0;

        foreach ($dates->unique()->filter(fn (string $d) => $d <= $today)->sort() as $date) {
            $day = Carbon::parse($date);
            $rule = RuleVersion::forDate($day);

            if ($rule === null) {
                continue;
            }

            $this->resolver->resolve($teacher, $day, $rule);
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, string>
     */
    private function resultDates(Teacher $teacher): Collection
    {
        return PeriodResult::query()
            ->current()
            ->where('teacher_id', $teacher->id)
            ->distinct()
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->values();
    }

    /**
     * School-local dates the scans fell on — the day a scan counts for,
     * not its UTC date.
     *
     * @param  list<int>  $teacherIds
     * @return Collection<int, string>
     */
    private function scanDates(array $teacherIds): Collection
    {
        $tz = config('attendance.timezone');

        return RawEvent::query()
            ->whereIn('teacher_id', $teacherIds)
            ->get(['id', 'event_time_device', 'event_time_server'])
            ->map(fn (RawEvent $e) => $e->effectiveTime()->clone()->setTimezone($tz)->toDateString())
            ->unique()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Teacher $teacher): array
    {
        return [
            'staff_no' => $teacher->staff_no,
            'full_name' => $teacher->full_name,
            'active_from' => $teacher->active_from?->toDateString(),
            'active_to' => $teacher->active_to?->toDateString(),
            'merged_into_id' => $teacher->merged_into_id,
        ];
    }
}

<?php

namespace App\Services\Attendance;

use App\Models\RawEvent;
use App\Models\RuleVersion;
use App\Models\Teacher;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes one teacher's day the moment a fingerprint scan for them is
 * stored, so the panel follows the terminal instead of waiting for the
 * next scheduled attendance:compute pass.
 *
 * Called from EventProcessor, the point the live stream and the backfill
 * both converge on, so neither path can drift from the other. The
 * scheduled every-ten-minutes pass and the nightly recovery stay as the
 * safety net: this is best-effort and swallows its own failures, because
 * the raw_events row is already stored by the time it runs and losing
 * the scan over a compute error would be the worse outcome.
 *
 * Same computation as attendance:compute for a single (teacher, date) —
 * DayResolver, under the rule version in force that day — so it is
 * idempotent and cannot produce anything the command would not.
 */
class AttendanceRefresher
{
    public function __construct(private readonly DayResolver $resolver) {}

    public function refreshForScan(RawEvent $event): void
    {
        if ($event->teacher_id === null) {
            return;
        }

        try {
            // The school-local date the scan fell on, not the UTC one: a
            // scan just after local midnight belongs to the new school day.
            $date = $event->effectiveTime()->clone()->setTimezone(config('attendance.timezone'))->startOfDay();

            $rule = RuleVersion::forDate($date);
            $teacher = Teacher::find($event->teacher_id);

            if ($rule === null || $teacher === null) {
                return;
            }

            // A scan still resolving to a merged duplicate (a backfill from
            // before its fingerprint moved) is the real teacher's scan.
            $teacher = $teacher->mergedInto ?? $teacher;

            $this->resolver->resolve($teacher, $date, $rule);
        } catch (\Throwable $e) {
            Log::channel('attendance')->warning('AttendanceRefresher: recompute after scan failed; the scheduled pass will retry', [
                'raw_event_id' => $event->id,
                'teacher_id' => $event->teacher_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

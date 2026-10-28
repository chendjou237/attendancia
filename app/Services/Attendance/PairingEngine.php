<?php

namespace App\Services\Attendance;

use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\AttendanceSession;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * §8.3: pairs raw_events to a session's scan-in / scan-out.
 *
 *   scan-in:  earliest event from session_start - pair_window_before
 *             through session_end
 *   scan-out: latest event from scan-in through session_end + pair_window_after
 *
 * All comparisons use RawEvent::effectiveTime() (event_time_device,
 * falling back to event_time_server) — never event_time_server alone.
 * For a live-stream event the two are seconds apart, but a backfilled
 * event's event_time_server is whenever the backfill command happened
 * to run, which has nothing to do with when the scan occurred; using
 * it here would leave every backfilled session unpairable, silently
 * defeating §7.2's primary recovery path. Caught via the demo data
 * generator simulating historical dates, where the gap between "when
 * the scan happened" and "when it was ingested" is large enough to be
 * unmissable — the same gap a multi-day outage produces for real.
 *
 * Every minute edge includes its last minute (RuleVersion::MINUTE_SLACK_SECONDS):
 * "15 minutes before" accepts 07:14:55 for a 07:30 start.
 *
 * Plus five decisions layered on top of the plain rule:
 *   - a scan before start + grace_late is a repeated arrival, never the
 *     scan-out (see the scan-out selection in pair())
 *   - debounce: successive scans from the same teacher inside
 *     min_scan_gap_seconds collapse to one before pairing (finding 1.4 —
 *     stops a double-tap from being read as a real scan-in/scan-out pair)
 *   - a boundary event may serve as both the scan-out of one session and
 *     the scan-in of the next; nothing here marks an event "consumed"
 *   - a paired interval shorter than min_session_minutes is UNPAIRED, not
 *     a valid (if suspiciously brief) attendance
 *   - §7.4 (retired): corridor matching is no longer enforced. Teachers
 *     scan on whichever terminal is nearest, so a scan from another
 *     corridor pairs exactly like one from the session's own corridor.
 *     config('attendance.enforce_location') is now a hard false (see the
 *     config file for why it is a constant rather than an env lookup),
 *     which leaves the filter below and the LocationMismatch anomaly
 *     unreachable; both are kept so period_results written under the old
 *     rule still read back, and the cross-corridor log line still records
 *     where a teacher actually tapped.
 */
class PairingEngine
{
    public function pair(AttendanceSession $session, RuleVersion $rule): void
    {
        $tz = config('attendance.timezone');
        $date = $session->date->toDateString();
        $enforceLocation = (bool) config('attendance.enforce_location');

        $sessionStart = Carbon::parse($date.' '.$session->firstSlot->start_time, $tz)->utc();
        $sessionEnd = Carbon::parse($date.' '.$session->lastSlot->end_time, $tz)->utc();

        $slack = RuleVersion::MINUTE_SLACK_SECONDS;
        $windowStart = $sessionStart->clone()->subMinutes($rule->pair_window_before_minutes)->subSeconds($slack);
        $windowEnd = $sessionEnd->clone()->addMinutes($rule->pair_window_after_minutes)->addSeconds($slack);
        // Until the arrival grace runs out, any further tap is the teacher
        // arriving again (a confirmation tap, a second terminal), never
        // leaving — see the scan-out selection below.
        $arrivalGraceEnd = $sessionStart->clone()->addMinutes($rule->grace_late_minutes)->addSeconds($slack);

        $allEvents = $this->debounce(
            RawEvent::query()
                ->whereIn('teacher_id', $session->teacher->scanSourceIds())
                ->where(function ($q) use ($windowStart, $windowEnd) {
                    $q->whereBetween('event_time_device', [$windowStart, $windowEnd])
                        ->orWhere(fn ($q2) => $q2->whereNull('event_time_device')
                            ->whereBetween('event_time_server', [$windowStart, $windowEnd]));
                })
                ->with('device')
                ->get()
                ->sortBy(fn (RawEvent $e) => $e->effectiveTime())
                ->values(),
            $rule->min_scan_gap_seconds,
        );

        $candidates = $enforceLocation
            ? $allEvents->filter(fn (RawEvent $e) => $e->device?->corridor_id === $session->corridor_id)->values()
            : $allEvents;

        $scanIn = $candidates->first(
            fn (RawEvent $e) => $e->effectiveTime()->betweenIncluded($windowStart, $sessionEnd)
        );

        if ($scanIn === null) {
            $session->scan_in_event_id = null;
            $session->scan_out_event_id = null;
            $session->state = SessionState::Unpaired;
            // LocationMismatch only when the corridor filter is what
            // removed a scan-in. Testing $allEvents->isNotEmpty() instead
            // was wrong: $allEvents also holds scans *after* the session
            // ended (the scan-out allowance), which can never be a scan-in,
            // so a teacher who tapped at 09:23 for a lesson ending 09:20 was
            // reported as "scanned in the wrong corridor" with enforcement
            // off.
            $filteredOutScanIn = $enforceLocation && $allEvents->contains(
                fn (RawEvent $e) => $e->effectiveTime()->betweenIncluded($windowStart, $sessionEnd)
            );
            $session->anomaly_code = $filteredOutScanIn
                ? SessionAnomaly::LocationMismatch->value
                : SessionAnomaly::NoScanIn->value;
            $session->save();

            return;
        }

        if (! $enforceLocation && $scanIn->device?->corridor_id !== $session->corridor_id) {
            Log::channel('attendance')->info('Cross-corridor match while enforce_location is off', [
                'session_id' => $session->id,
                'teacher_id' => $session->teacher_id,
                'expected_corridor_id' => $session->corridor_id,
                'actual_corridor_id' => $scanIn->device?->corridor_id,
                'raw_event_id' => $scanIn->id,
            ]);
        }

        // Excludes scan-in's own row: a single tap is not a pair with
        // itself, and without this a lone scan-in self-selects as its own
        // scan-out (0-minute "session") instead of correctly reporting
        // that no scan-out ever arrived.
        //
        // And nothing before the arrival grace ends can be the departure.
        // JOSIAH, 2026-10-13: in at 07:20:59, a second tap at 07:32:41 on
        // another terminal was read as "left at 07:32" and both periods
        // went ABSENT. A re-tap is far likelier than walking out twelve
        // minutes into a lesson; if no later scan exists the session is
        // pending (no_scan_out) for a human, never a silent absence.
        $departureFrom = $scanIn->effectiveTime()->greaterThan($arrivalGraceEnd)
            ? $scanIn->effectiveTime()
            : $arrivalGraceEnd;

        $scanOut = $candidates
            ->filter(fn (RawEvent $e) => $e->id !== $scanIn->id
                && $e->effectiveTime()->greaterThanOrEqualTo($departureFrom)
                && $e->effectiveTime()->lessThanOrEqualTo($windowEnd))
            ->last();

        if ($scanOut === null) {
            $session->scan_in_event_id = $scanIn->id;
            $session->scan_out_event_id = null;
            $session->state = SessionState::Unpaired;
            $session->anomaly_code = SessionAnomaly::NoScanOut->value;
            $session->save();

            return;
        }

        $minutes = $scanIn->effectiveTime()->diffInMinutes($scanOut->effectiveTime(), true);

        $session->scan_in_event_id = $scanIn->id;
        $session->scan_out_event_id = $scanOut->id;

        if ($minutes < $rule->min_session_minutes) {
            $session->state = SessionState::Unpaired;
            $session->anomaly_code = SessionAnomaly::TooShort->value;
        } else {
            $session->state = SessionState::Paired;
            $session->anomaly_code = null;
        }

        $session->save();
    }

    /**
     * Collapses successive events that land within $gapSeconds of the
     * previously kept one, keeping the earliest of each cluster. Applied
     * to a single teacher's already-time-sorted events, so this is a
     * plain linear scan — no event is deleted or mutated, this only
     * changes what the pairing logic considers.
     *
     * @param  Collection<int, RawEvent>  $events
     * @return Collection<int, RawEvent>
     */
    private function debounce(Collection $events, int $gapSeconds): Collection
    {
        $kept = collect();
        $lastKept = null;

        foreach ($events as $event) {
            if ($lastKept !== null && $event->effectiveTime()->diffInSeconds($lastKept->effectiveTime(), true) < $gapSeconds) {
                continue;
            }

            $kept->push($event);
            $lastKept = $event;
        }

        return $kept;
    }
}

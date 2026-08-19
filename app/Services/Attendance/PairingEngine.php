<?php

namespace App\Services\Attendance;

use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\AttendanceSession;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * §8.3: pairs raw_events to a session's scan-in / scan-out.
 *
 *   scan-in:  earliest event from session_start - pair_window_before
 *             through session_end
 *   scan-out: latest event from scan-in through session_end + pair_window_after
 *
 * Plus three decisions layered on top of the plain rule:
 *   - debounce: successive scans from the same teacher inside
 *     min_scan_gap_seconds collapse to one before pairing (finding 1.4 —
 *     stops a double-tap from being read as a real scan-in/scan-out pair)
 *   - a boundary event may serve as both the scan-out of one session and
 *     the scan-in of the next; nothing here marks an event "consumed"
 *   - a paired interval shorter than min_session_minutes is UNPAIRED, not
 *     a valid (if suspiciously brief) attendance
 */
class PairingEngine
{
    public function pair(AttendanceSession $session, RuleVersion $rule): void
    {
        $tz = config('attendance.timezone');
        $date = $session->date->toDateString();

        $sessionStart = Carbon::parse($date.' '.$session->firstSlot->start_time, $tz)->utc();
        $sessionEnd = Carbon::parse($date.' '.$session->lastSlot->end_time, $tz)->utc();

        $windowStart = $sessionStart->clone()->subMinutes($rule->pair_window_before_minutes);
        $windowEnd = $sessionEnd->clone()->addMinutes($rule->pair_window_after_minutes);

        $allEvents = $this->debounce(
            RawEvent::query()
                ->where('teacher_id', $session->teacher_id)
                ->whereBetween('event_time_server', [$windowStart, $windowEnd])
                ->with('device')
                ->orderBy('event_time_server')
                ->get(),
            $rule->min_scan_gap_seconds,
        );

        $inCorridor = $allEvents->filter(
            fn (RawEvent $e) => $e->device?->corridor_id === $session->corridor_id
        )->values();

        $scanIn = $inCorridor->first(
            fn (RawEvent $e) => $e->event_time_server->betweenIncluded($windowStart, $sessionEnd)
        );

        if ($scanIn === null) {
            $session->scan_in_event_id = null;
            $session->scan_out_event_id = null;
            $session->state = SessionState::Unpaired;
            $session->anomaly_code = $allEvents->isNotEmpty()
                ? SessionAnomaly::LocationMismatch->value
                : SessionAnomaly::NoScanIn->value;
            $session->save();

            return;
        }

        // Excludes scan-in's own row: a single tap is not a pair with
        // itself, and without this a lone scan-in self-selects as its own
        // scan-out (0-minute "session") instead of correctly reporting
        // that no scan-out ever arrived.
        $scanOut = $inCorridor
            ->filter(fn (RawEvent $e) => $e->id !== $scanIn->id
                && $e->event_time_server->betweenIncluded($scanIn->event_time_server, $windowEnd))
            ->last();

        if ($scanOut === null) {
            $session->scan_in_event_id = $scanIn->id;
            $session->scan_out_event_id = null;
            $session->state = SessionState::Unpaired;
            $session->anomaly_code = SessionAnomaly::NoScanOut->value;
            $session->save();

            return;
        }

        $minutes = $scanIn->event_time_server->diffInMinutes($scanOut->event_time_server, true);

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
            if ($lastKept !== null && $event->event_time_server->diffInSeconds($lastKept->event_time_server, true) < $gapSeconds) {
                continue;
            }

            $kept->push($event);
            $lastKept = $event;
        }

        return $kept;
    }
}

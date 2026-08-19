<?php

namespace App\Services\Attendance;

use App\Enums\SessionState;
use App\Models\AttendanceSession;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * §4: "A session is a maximal run of consecutive scheduled periods with
 * the same class_code in the same room."
 *
 * Splitting rules, in order of how often they'll come up:
 *   - same class, consecutive periods            -> same session
 *   - same class, separated only by break slot(s) -> same session (never
 *     split on a break, however long — decision, finding "long breaks")
 *   - different class, or different room          -> new session
 *   - a free (unscheduled) slot in between         -> new session, even if
 *     the class code on both sides matches
 */
class SessionBuilder
{
    /**
     * Pure grouping: no DB writes. Returns groupings in slot order.
     *
     * @return Collection<int, SessionGrouping>
     */
    public function group(Teacher $teacher, CarbonInterface $date): Collection
    {
        $version = $teacher->timetableVersionFor($date);

        if ($version === null) {
            return collect();
        }

        $entries = $version->entries()
            ->where('day_of_week', $date->dayOfWeek)
            ->with('slot')
            ->get()
            ->sortBy(fn (TimetableEntry $e) => $e->slot->seq)
            ->values();

        if ($entries->isEmpty()) {
            return collect();
        }

        $slotsBySeq = PeriodSlot::forDate($date)->keyBy('seq');

        $groupings = collect();
        /** @var Collection<int, TimetableEntry> $current */
        $current = collect();

        $flush = function () use (&$current, &$groupings) {
            if ($current->isEmpty()) {
                return;
            }

            /** @var TimetableEntry $first */
            $first = $current->first();
            /** @var TimetableEntry $last */
            $last = $current->last();
            /** @var Room $room */
            $room = $first->room;

            $groupings->push(new SessionGrouping(
                classCodeId: $first->class_code_id,
                roomId: $first->room_id,
                corridorId: $room->corridor_id,
                firstSlot: $first->slot,
                lastSlot: $last->slot,
                periodSlotIds: $current->pluck('slot_id')->all(),
            ));

            $current = collect();
        };

        foreach ($entries as $entry) {
            if ($current->isEmpty()) {
                $current->push($entry);

                continue;
            }

            /** @var TimetableEntry $previous */
            $previous = $current->last();
            $sameClassAndRoom = $entry->class_code_id === $previous->class_code_id
                && $entry->room_id === $previous->room_id;

            if ($sameClassAndRoom && $this->gapIsAllBreaks($slotsBySeq, $previous->slot->seq, $entry->slot->seq)) {
                $current->push($entry);

                continue;
            }

            $flush();
            $current->push($entry);
        }

        $flush();

        return $groupings;
    }

    /**
     * Every slot strictly between $fromSeq and $toSeq must be a break for
     * the run either side to stay one session. A gap that isn't fully
     * accounted for by break slots (a genuinely free/unscheduled period,
     * or a missing slot definition) always splits — ambiguity favours a
     * smaller session and an extra scan, never a silent merge.
     */
    private function gapIsAllBreaks(Collection $slotsBySeq, int $fromSeq, int $toSeq): bool
    {
        for ($seq = $fromSeq + 1; $seq < $toSeq; $seq++) {
            $slot = $slotsBySeq->get($seq);

            if ($slot === null || ! $slot->is_break) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-derives the individual teaching-period slots a persisted session
     * covers, by re-running the same timetable query group() uses,
     * scoped to this session's own boundaries. No pivot table stores
     * this redundantly — session_id + slot_id on period_results is
     * enough, and re-deriving keeps the timetable the single source of
     * truth (as long as it hasn't changed since the session was built;
     * see the no-deletion note on persist()).
     *
     * @return Collection<int, PeriodSlot>
     */
    public function periodSlotsFor(AttendanceSession $session): Collection
    {
        $version = $session->teacher->timetableVersionFor($session->date);

        if ($version === null) {
            return collect();
        }

        return $version->entries()
            ->where('day_of_week', $session->date->dayOfWeek)
            ->where('class_code_id', $session->class_code_id)
            ->where('room_id', $session->room_id)
            ->with('slot')
            ->get()
            ->pluck('slot')
            ->filter(fn (PeriodSlot $slot) => $slot->seq >= $session->firstSlot->seq && $slot->seq <= $session->lastSlot->seq)
            ->sortBy('seq')
            ->values();
    }

    /**
     * Persists groupings as AttendanceSession rows, upserted on
     * (teacher_id, date, first_slot_id) so re-running for a date whose
     * timetable hasn't changed is idempotent and never disturbs pairing
     * already recorded on an existing row (scan_in/out, state).
     *
     * Does not delete sessions that no longer match a current grouping —
     * a timetable retroactively changing after scans already happened is
     * an open question (see plan), not something to resolve by deleting
     * data quietly.
     *
     * @param  Collection<int, SessionGrouping>  $groupings
     * @return Collection<int, AttendanceSession>
     */
    public function persist(Teacher $teacher, CarbonInterface $date, Collection $groupings): Collection
    {
        return $groupings->map(function (SessionGrouping $grouping) use ($teacher, $date) {
            /** @var AttendanceSession $session */
            $session = AttendanceSession::query()->firstOrNew([
                'teacher_id' => $teacher->id,
                'date' => $date->toDateString(),
                'first_slot_id' => $grouping->firstSlot->id,
            ]);

            $session->class_code_id = $grouping->classCodeId;
            $session->room_id = $grouping->roomId;
            $session->corridor_id = $grouping->corridorId;
            $session->last_slot_id = $grouping->lastSlot->id;
            $session->state ??= SessionState::Unpaired;
            $session->save();

            return $session;
        });
    }
}

<?php

namespace App\Services\Attendance;

use App\Enums\DayType;
use App\Models\AttendanceSession;
use App\Models\CalendarDay;
use App\Models\PeriodResult;
use App\Models\RuleVersion;
use App\Models\Teacher;
use Carbon\CarbonInterface;

/**
 * §8.1: resolves one teacher's expected day before anything gets built,
 * then drives the rest of the pipeline (session builder -> pairing ->
 * rule engine) accordingly.
 *
 *   public_holiday / school_closure -> no expected sessions, no results
 *   half_day                       -> only slots inside the shortened
 *                                      day are expected
 *   classes_suspended              -> per session: not expected at all if
 *                                      the session's class_code is in
 *                                      scope (empty scope = whole school)
 *                                      — no result, out of the month's
 *                                      total, like a holiday. Until
 *                                      2026-10 this wrote PRESENT_ADMIN
 *                                      (paid without a scan); those rows
 *                                      still read back. Out-of-scope
 *                                      classes take the normal scan-based
 *                                      path, since a partial suspension
 *                                      leaves them running as usual
 *   teaching (default, no calendar_days row)
 *                                   -> normal path for every session
 *
 * Absent a calendar_days row for the date, the day is teaching — only
 * exceptions get a row, not every date in the calendar.
 *
 * Retro-marking (a closure declared same-day or the night before, per
 * §8.1) works by simply calling resolve() again: a date reclassified to
 * a no-expected-sessions day flips every current period_result for that
 * (teacher, date) to is_current = false rather than deleting it (§14),
 * and a date reclassified back to teaching recomputes fresh through the
 * normal path.
 */
class DayResolver
{
    public function __construct(
        private readonly SessionBuilder $sessionBuilder,
        private readonly PairingEngine $pairingEngine,
        private readonly RuleEngine $ruleEngine,
    ) {}

    public function resolve(Teacher $teacher, CarbonInterface $date, RuleVersion $rule): void
    {
        $calendarDay = CalendarDay::query()->where('date', $date->toDateString())->first();
        $dayType = $calendarDay?->day_type ?? DayType::Teaching;

        // A teacher outside their active range (left the school, or a
        // deactivated duplicate) is expected nowhere — same outcome as a
        // closure. attendance:compute's default run already skips them, but
        // --teacher and AttendanceRefresher don't, and a deactivation needs
        // this to retire results already written after active_to.
        if ($dayType->hasNoExpectedSessions() || ! $teacher->isActiveOn($date)) {
            $this->clearCurrentResults($teacher, $date);

            return;
        }

        $maxSeq = null;
        if ($dayType === DayType::HalfDay && $calendarDay?->half_day_cutoff_slot_id !== null) {
            $maxSeq = $calendarDay->halfDayCutoffSlot->seq;
        }

        $groupings = $this->sessionBuilder->group($teacher, $date);
        $sessions = $this->sessionBuilder->persist($teacher, $date, $groupings);

        foreach ($sessions as $session) {
            $isSuspended = $dayType === DayType::ClassesSuspended
                && $calendarDay->suspendsClassCode($session->class_code_id);

            if ($isSuspended) {
                $this->retireSessionResults($session);

                continue;
            }

            $this->pairingEngine->pair($session, $rule);
            $session->refresh()->load('scanInEvent', 'scanOutEvent', 'firstSlot', 'lastSlot', 'teacher', 'classCode');

            $this->ruleEngine->computeForSession($session, $rule, $maxSeq);
        }
    }

    /**
     * A suspended class's periods are not expected that day: like a
     * holiday, they leave the month's total rather than being paid as
     * PRESENT_ADMIN (decision, 2026-10). Anything already computed for
     * them — the day marked after the fact — is retired, never deleted.
     */
    private function retireSessionResults(AttendanceSession $session): void
    {
        $slotIds = $this->sessionBuilder->periodSlotsFor($session)->pluck('id');

        PeriodResult::query()
            ->where('teacher_id', $session->teacher_id)
            ->where('date', $session->date->toDateString())
            ->whereIn('slot_id', $slotIds)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }

    private function clearCurrentResults(Teacher $teacher, CarbonInterface $date): void
    {
        PeriodResult::query()
            ->where('teacher_id', $teacher->id)
            ->where('date', $date->toDateString())
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}

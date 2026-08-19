<?php

namespace App\Services\Attendance;

use App\Enums\DayType;
use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
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
 *   classes_suspended              -> per session: PRESENT_ADMIN with no
 *                                      scan required if the session's
 *                                      class_code is in scope (decision:
 *                                      scoped to class codes, empty scope
 *                                      = whole school); otherwise the
 *                                      normal scan-based path, since a
 *                                      partial suspension leaves other
 *                                      classes running as usual
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
        private readonly PeriodResultWriter $writer,
    ) {}

    public function resolve(Teacher $teacher, CarbonInterface $date, RuleVersion $rule): void
    {
        $calendarDay = CalendarDay::query()->whereDate('date', $date->toDateString())->first();
        $dayType = $calendarDay?->day_type ?? DayType::Teaching;

        if ($dayType->hasNoExpectedSessions()) {
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
            $isAdministrative = $dayType === DayType::ClassesSuspended
                && $calendarDay->suspendsClassCode($session->class_code_id);

            if ($isAdministrative) {
                $this->writeAdministrative($session, $rule, $maxSeq);

                continue;
            }

            $this->pairingEngine->pair($session, $rule);
            $session->refresh()->load('scanInEvent', 'scanOutEvent', 'firstSlot', 'lastSlot', 'teacher', 'classCode');

            $this->ruleEngine->computeForSession($session, $rule, $maxSeq);
        }
    }

    private function writeAdministrative(AttendanceSession $session, RuleVersion $rule, ?int $maxSeq): void
    {
        $slots = $this->sessionBuilder->periodSlotsFor($session);

        if ($maxSeq !== null) {
            $slots = $slots->filter(fn ($slot) => $slot->seq <= $maxSeq);
        }

        foreach ($slots as $slot) {
            $this->writer->write(
                $session->teacher, $session->date, $slot, $session->classCode,
                PeriodStatus::PresentAdmin, PeriodSource::Administrative, $rule, $session,
            );
        }
    }

    private function clearCurrentResults(Teacher $teacher, CarbonInterface $date): void
    {
        PeriodResult::query()
            ->where('teacher_id', $teacher->id)
            ->whereDate('date', $date->toDateString())
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}

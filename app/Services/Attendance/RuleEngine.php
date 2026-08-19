<?php

namespace App\Services\Attendance;

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\AttendanceSession;
use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * §5, §8.4: applies the one grace-window rule to every period_slot a
 * session covers.
 *
 *   PRESENT if the paired scan interval COVERS
 *   [period_start + grace_late, period_end - grace_early].
 *   Otherwise ABSENT.
 *
 * "Covers" means the interval starts at or before the window's start
 * and ends at or after the window's end — not merely overlaps. No
 * first/last-period special case (§5): every slot gets exactly the
 * same test.
 *
 * A session that never paired doesn't get evaluated against the rule
 * at all — every slot becomes UNPAIRED (or LOCATION_MISMATCH, per the
 * anomaly the pairing engine recorded), never a silent ABSENT (§14).
 */
class RuleEngine
{
    public function __construct(
        private readonly SessionBuilder $sessionBuilder,
        private readonly PeriodResultWriter $writer,
    ) {}

    /**
     * $maxSeq (§13.2 half-day cutoff): when given, slots beyond it are
     * simply not written at all — not even as UNPAIRED — because they
     * were never expected in the first place. Pairing still runs against
     * the session's full natural boundaries; only which slots receive a
     * period_result is restricted.
     *
     * @return Collection<int, \App\Models\PeriodResult>
     */
    public function computeForSession(AttendanceSession $session, RuleVersion $rule, ?int $maxSeq = null): Collection
    {
        $slots = $this->sessionBuilder->periodSlotsFor($session);

        if ($maxSeq !== null) {
            $slots = $slots->filter(fn (PeriodSlot $slot) => $slot->seq <= $maxSeq)->values();
        }

        if ($session->state === SessionState::Unpaired) {
            $status = $session->anomaly_code === SessionAnomaly::LocationMismatch->value
                ? PeriodStatus::LocationMismatch
                : PeriodStatus::Unpaired;

            return $slots->map(fn (PeriodSlot $slot) => $this->writer->write(
                $session->teacher, $session->date, $slot, $session->classCode,
                $status, PeriodSource::Scan, $rule, $session,
            ));
        }

        $tz = config('attendance.timezone');
        $date = $session->date->toDateString();
        $scanIn = $session->scanInEvent->event_time_server;
        $scanOut = $session->scanOutEvent->event_time_server;

        return $slots->map(function (PeriodSlot $slot) use ($session, $rule, $tz, $date, $scanIn, $scanOut) {
            $periodStart = Carbon::parse($date.' '.$slot->start_time, $tz)->utc();
            $periodEnd = Carbon::parse($date.' '.$slot->end_time, $tz)->utc();

            $requiredStart = $periodStart->clone()->addMinutes($rule->grace_late_minutes);
            $requiredEnd = $periodEnd->clone()->subMinutes($rule->grace_early_minutes);

            $covers = $scanIn->lessThanOrEqualTo($requiredStart) && $scanOut->greaterThanOrEqualTo($requiredEnd);

            return $this->writer->write(
                $session->teacher, $session->date, $slot, $session->classCode,
                $covers ? PeriodStatus::Present : PeriodStatus::Absent,
                PeriodSource::Scan, $rule, $session,
            );
        });
    }
}

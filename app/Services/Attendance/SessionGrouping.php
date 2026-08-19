<?php

namespace App\Services\Attendance;

use App\Models\PeriodSlot;

/**
 * A pure value object: one maximal run of consecutive scheduled periods
 * with the same class_code in the same room (§4), before it becomes an
 * AttendanceSession row.
 */
final class SessionGrouping
{
    /**
     * @param  array<int, int>  $periodSlotIds  the individual teaching-period
     *                                          slot ids covered, in order —
     *                                          one period_result is produced
     *                                          per id, not per slot in the
     *                                          first..last numeric range
     *                                          (breaks never get a result row
     *                                          because they never get a
     *                                          timetable_entry).
     */
    public function __construct(
        public readonly int $classCodeId,
        public readonly int $roomId,
        public readonly int $corridorId,
        public readonly PeriodSlot $firstSlot,
        public readonly PeriodSlot $lastSlot,
        public readonly array $periodSlotIds,
    ) {}
}

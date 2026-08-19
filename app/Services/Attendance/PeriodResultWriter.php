<?php

namespace App\Services\Attendance;

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Models\AttendanceSession;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Models\Teacher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The single upsert path for period_results, shared by the rule engine
 * (scan-derived results) and day resolution (administrative results for
 * suspended/closed days), so both honour the same versioning contract:
 *
 *   - recomputing under the SAME rule_version_id updates that row in
 *     place (idempotent — §8's "re-running for a date produces
 *     identical results unless inputs or rule version changed")
 *   - recomputing under a DIFFERENT rule_version_id inserts a new row
 *     and flips every other row for that (teacher, date, slot) to
 *     is_current = false, so old results are never destroyed (§14)
 *     while "the" current figure stays a single indexed lookup
 *   - override_status/reason/by/at are never touched here — a human
 *     override on a since-superseded row is a decision, not a stale
 *     computation, and effectiveStatus() already prefers it regardless
 *     of what the freshly computed status underneath says
 */
class PeriodResultWriter
{
    public function write(
        Teacher $teacher,
        CarbonInterface $date,
        PeriodSlot $slot,
        ClassCode $classCode,
        PeriodStatus $status,
        PeriodSource $source,
        RuleVersion $rule,
        ?AttendanceSession $session,
    ): PeriodResult {
        return DB::transaction(function () use ($teacher, $date, $slot, $classCode, $status, $source, $rule, $session) {
            PeriodResult::query()
                ->where('teacher_id', $teacher->id)
                ->whereDate('date', $date->toDateString())
                ->where('slot_id', $slot->id)
                ->where('rule_version_id', '!=', $rule->id)
                ->update(['is_current' => false]);

            $result = PeriodResult::query()->firstOrNew([
                'teacher_id' => $teacher->id,
                'date' => $date->toDateString(),
                'slot_id' => $slot->id,
                'rule_version_id' => $rule->id,
            ]);

            $result->session_id = $session?->id;
            $result->class_code_id = $classCode->id;
            $result->status = $status;
            $result->source = $source;
            $result->computed_at = now();
            $result->is_current = true;
            $result->save();

            return $result;
        });
    }
}

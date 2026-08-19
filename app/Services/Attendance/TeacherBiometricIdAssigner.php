<?php

namespace App\Services\Attendance;

use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The invariant `teacher_biometric_ids` itself can't enforce (see the
 * migration): at most one row per biometric_id may be active
 * (valid_to null) at a time. A partial unique index isn't portable
 * across MySQL and SQLite, so it's enforced here instead.
 */
class TeacherBiometricIdAssigner
{
    /**
     * Assigns $biometricId to $teacher from $validFrom. If another
     * teacher currently holds that biometric_id, their mapping is
     * closed out the day before — this is the re-enrolment case (a
     * worn fingerprint re-registered under the same device id), not
     * silently letting two teachers share one id.
     */
    public function assign(Teacher $teacher, string $biometricId, CarbonInterface $validFrom): TeacherBiometricId
    {
        return DB::transaction(function () use ($teacher, $biometricId, $validFrom) {
            TeacherBiometricId::query()
                ->where('biometric_id', $biometricId)
                ->whereNull('valid_to')
                ->where('teacher_id', '!=', $teacher->id)
                ->update(['valid_to' => $validFrom->clone()->subDay()->toDateString()]);

            // Same teacher re-assigned the same id: nothing to do.
            $existing = TeacherBiometricId::query()
                ->where('biometric_id', $biometricId)
                ->where('teacher_id', $teacher->id)
                ->whereNull('valid_to')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return TeacherBiometricId::create([
                'teacher_id' => $teacher->id,
                'biometric_id' => $biometricId,
                'valid_from' => $validFrom->toDateString(),
                'valid_to' => null,
            ]);
        });
    }
}

<?php

namespace App\Filament\Resources\TeacherBiometricIds\Pages;

use App\Filament\Resources\TeacherBiometricIds\TeacherBiometricIdResource;
use App\Models\Teacher;
use App\Services\Attendance\TeacherBiometricIdAssigner;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherBiometricId extends CreateRecord
{
    protected static string $resource = TeacherBiometricIdResource::class;

    /**
     * Routes creation through the assigner rather than a bare insert,
     * so the "at most one active mapping per biometric_id" invariant
     * (enforced in app code, not the DB — see the migration) applies
     * to admin-panel assignment too, not only programmatic callers.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $teacher = Teacher::findOrFail($data['teacher_id']);

        $mapping = (new TeacherBiometricIdAssigner)->assign(
            $teacher,
            $data['biometric_id'],
            Carbon::parse($data['valid_from']),
        );

        if (filled($data['valid_to'] ?? null)) {
            $mapping->update(['valid_to' => $data['valid_to']]);
        }

        return $mapping;
    }
}

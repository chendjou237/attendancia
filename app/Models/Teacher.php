<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EmploymentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['staff_no', 'full_name', 'employment_type', 'active_from', 'active_to'])]
class Teacher extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'active_from' => DateOnly::class,
            'active_to' => DateOnly::class,
        ];
    }

    public function biometricIds(): HasMany
    {
        return $this->hasMany(TeacherBiometricId::class);
    }

    public function timetableVersions(): HasMany
    {
        return $this->hasMany(TimetableVersion::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function periodResults(): HasMany
    {
        return $this->hasMany(PeriodResult::class);
    }

    /**
     * The timetable version governing this teacher's schedule on $date,
     * per §3: "one grid per teacher... versioned by valid_from".
     */
    public function timetableVersionFor(CarbonInterface $date): ?TimetableVersion
    {
        // Plain <=/>= comparisons are safe because App\Casts\DateOnly
        // stores these columns as bare "Y-m-d" on every engine — see that
        // cast for why this used to need whereDate() and what it cost.
        return $this->timetableVersions()
            ->where('valid_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date->toDateString()))
            ->orderByDesc('valid_from')
            ->first();
    }
}

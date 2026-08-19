<?php

namespace App\Models;

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
            'active_from' => 'date',
            'active_to' => 'date',
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
        // whereDate(), not where('valid_from', '<=', ...): the `date` cast
        // serialises to "Y-m-d H:i:s" on save, so a plain string <=/>=
        // comparison silently excludes a row whose valid_from is the exact
        // reference day (fine on MySQL's real DATE type, which discards
        // the time part on write, but wrong on any DB that stores dates
        // as text). whereDate() compares on the date part only, correctly
        // on both.
        return $this->timetableVersions()
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderByDesc('valid_from')
            ->first();
    }
}

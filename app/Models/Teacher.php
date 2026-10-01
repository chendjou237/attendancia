<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EmploymentType;
use App\Models\Contracts\GuardsDeletion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['staff_no', 'full_name', 'employment_type', 'active_from', 'active_to'])]
class Teacher extends Model implements GuardsDeletion
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

    /**
     * The real record this one duplicated. Deliberately not fillable: only
     * App\Services\Attendance\TeacherDeactivator sets it, because setting
     * it without recomputing both teachers leaves stale period_results.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function isActiveOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->active_from->toDateString() <= $day
            && ($this->active_to === null || $this->active_to->toDateString() >= $day);
    }

    /**
     * Teacher ids whose scans count as this teacher's: its own, plus any
     * duplicate merged into it. raw_events is append-only, so a duplicate's
     * scans keep the duplicate's teacher_id forever — pairing widens the
     * lookup instead of rewriting them.
     *
     * @return list<int>
     */
    public function scanSourceIds(): array
    {
        return [$this->id, ...$this->duplicates()->pluck('id')->all()];
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
     * sessions and period_results reference teachers with RESTRICT: once
     * attendance has been computed for a teacher, it is a payroll input and
     * the row stays. Checked up front so the panel can say so instead of
     * failing on the foreign key.
     */
    public function deletionBlockedReason(): ?string
    {
        if ($this->sessions()->exists() || $this->periodResults()->exists()) {
            return __('panel.resources.teachers.cannot_delete_has_attendance', ['name' => $this->full_name]);
        }

        return null;
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
            // id desc is the tiebreak, not decoration: the timetable screen
            // defaults a new version's valid_from to today, so entering a
            // replacement grid for a version that already starts today
            // leaves two open versions on the same date. Ordering on
            // valid_from alone left which one governs up to the database
            // (in practice the older row), which is exactly the "I made a
            // new timetable and attendance still uses the old one" report.
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }
}

<?php

namespace App\Models;

use App\Enums\DayType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['date', 'day_type', 'note', 'half_day_cutoff_slot_id', 'marked_by', 'marked_at'])]
class CalendarDay extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day_type' => DayType::class,
            'marked_at' => 'datetime',
        ];
    }

    public function halfDayCutoffSlot(): BelongsTo
    {
        return $this->belongsTo(PeriodSlot::class, 'half_day_cutoff_slot_id');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * Class codes a classes_suspended day applies to. Empty means the
     * whole school (decision: "scoped to class codes", finding 1.7).
     */
    public function suspendedClassCodes(): BelongsToMany
    {
        return $this->belongsToMany(ClassCode::class, 'calendar_day_class_codes');
    }

    public function suspendsClassCode(int $classCodeId): bool
    {
        if ($this->day_type !== DayType::ClassesSuspended) {
            return false;
        }

        $scoped = $this->suspendedClassCodes()->pluck('class_codes.id');

        return $scoped->isEmpty() || $scoped->contains($classCodeId);
    }
}

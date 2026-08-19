<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['day_of_week', 'seq', 'start_time', 'end_time', 'is_break', 'valid_from', 'valid_to'])]
class PeriodSlot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_break' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class, 'slot_id');
    }

    /**
     * The slots active for $date's day-of-week, in sequence order.
     * Versioned (finding 1.6): picks the grid whose valid_from/valid_to
     * window covers $date, so a mid-year bell-schedule change never
     * rewrites how a past date was resolved.
     *
     * @return Collection<int, self>
     */
    public static function forDate(CarbonInterface $date): Collection
    {
        // whereDate(), not a plain <=/>= string comparison — see
        // Teacher::timetableVersionFor() for why a row whose valid_from is
        // the exact reference day would otherwise be silently excluded.
        return static::query()
            ->where('day_of_week', $date->dayOfWeek)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderBy('seq')
            ->get();
    }
}

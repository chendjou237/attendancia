<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'grace_late_minutes', 'grace_early_minutes',
    'pair_window_before_minutes', 'pair_window_after_minutes',
    'min_scan_gap_seconds', 'min_session_minutes', 'hours_per_period',
    'valid_from', 'created_by', 'note',
])]
class RuleVersion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hours_per_period' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * §14: never hardcode a threshold; every computed row carries the
     * rule version that produced it. This resolves which version governs
     * a given date.
     */
    public static function forDate(CarbonInterface $date): ?self
    {
        return static::query()
            ->where('valid_from', '<=', $date->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }
}

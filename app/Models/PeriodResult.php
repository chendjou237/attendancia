<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'session_id', 'teacher_id', 'date', 'slot_id', 'class_code_id',
    'status', 'source', 'rule_version_id', 'computed_at', 'is_current',
    'override_status', 'override_reason', 'override_by', 'override_at', 'notice_id',
])]
class PeriodResult extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'status' => PeriodStatus::class,
            'source' => PeriodSource::class,
            'computed_at' => 'datetime',
            'is_current' => 'boolean',
            'override_status' => PeriodStatus::class,
            'override_at' => 'datetime',
        ];
    }

    /**
     * The status that actually counts, honouring a principal override
     * without discarding the computed status it replaced (§9.2, §9).
     */
    public function effectiveStatus(): PeriodStatus
    {
        return $this->override_status ?? $this->status;
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(PeriodSlot::class, 'slot_id');
    }

    public function classCode(): BelongsTo
    {
        return $this->belongsTo(ClassCode::class);
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(RuleVersion::class);
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}

<?php

namespace App\Models;

use App\Enums\SessionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §4's "session": a maximal run of consecutive scheduled periods with the
 * same class_code in the same room. Named AttendanceSession (table
 * `sessions`) to avoid colliding with Laravel's own session concept.
 */
#[Fillable([
    'teacher_id', 'date', 'class_code_id', 'room_id', 'corridor_id',
    'first_slot_id', 'last_slot_id', 'scan_in_event_id', 'scan_out_event_id',
    'state', 'anomaly_code',
])]
class AttendanceSession extends Model
{
    use HasFactory;

    protected $table = 'sessions';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'state' => SessionState::class,
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classCode(): BelongsTo
    {
        return $this->belongsTo(ClassCode::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }

    public function firstSlot(): BelongsTo
    {
        return $this->belongsTo(PeriodSlot::class, 'first_slot_id');
    }

    public function lastSlot(): BelongsTo
    {
        return $this->belongsTo(PeriodSlot::class, 'last_slot_id');
    }

    public function scanInEvent(): BelongsTo
    {
        return $this->belongsTo(RawEvent::class, 'scan_in_event_id');
    }

    public function scanOutEvent(): BelongsTo
    {
        return $this->belongsTo(RawEvent::class, 'scan_out_event_id');
    }

    public function periodResults(): HasMany
    {
        return $this->hasMany(PeriodResult::class, 'session_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_id', 'day_of_week', 'slot_id', 'class_code_id', 'room_id'])]
class TimetableEntry extends Model
{
    use HasFactory;

    public function version(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class, 'version_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(PeriodSlot::class, 'slot_id');
    }

    public function classCode(): BelongsTo
    {
        return $this->belongsTo(ClassCode::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

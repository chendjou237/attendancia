<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ReportState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['month', 'state', 'snapshot_json', 'generated_at', 'approved_by', 'approved_at', 'sent_to_hr_at'])]
class MonthlyReport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'month' => DateOnly::class,
            'state' => ReportState::class,
            'snapshot_json' => 'array',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_to_hr_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

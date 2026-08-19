<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['corridor_id', 'code', 'name'])]
class Room extends Model
{
    use HasFactory;

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }
}

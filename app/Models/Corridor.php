<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'name'])]
class Corridor extends Model
{
    use HasFactory;

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function device(): HasOne
    {
        return $this->hasOne(Device::class);
    }
}

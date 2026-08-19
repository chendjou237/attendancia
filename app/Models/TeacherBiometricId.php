<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['teacher_id', 'biometric_id', 'valid_from', 'valid_to'])]
class TeacherBiometricId extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Who held $biometricId at $at — not "who holds it today". A
     * backfilled historical event must resolve against whoever the
     * device id belonged to at the time, so re-enrolment after a worn
     * fingerprint doesn't misattribute old scans to the new holder.
     */
    public static function resolve(string $biometricId, CarbonInterface $at): ?Teacher
    {
        $mapping = static::query()
            ->where('biometric_id', $biometricId)
            ->whereDate('valid_from', '<=', $at->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $at->toDateString()))
            ->orderByDesc('valid_from')
            ->first();

        return $mapping?->teacher;
    }
}

<?php

namespace Database\Factories;

use App\Enums\TimetableState;
use App\Models\Teacher;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableVersion>
 */
class TimetableVersionFactory extends Factory
{
    protected $model = TimetableVersion::class;

    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => null,
            'state' => TimetableState::Approved,
            'entered_by' => null,
            'approved_by' => null,
            'approved_at' => null,
            'note' => null,
        ];
    }
}

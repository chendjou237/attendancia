<?php

namespace Database\Factories;

use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherBiometricId>
 */
class TeacherBiometricIdFactory extends Factory
{
    protected $model = TeacherBiometricId::class;

    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'biometric_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => null,
        ];
    }
}

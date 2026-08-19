<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        return [
            'staff_no' => fake()->unique()->numerify('T-####'),
            'full_name' => fake()->name(),
            'employment_type' => EmploymentType::Hourly,
            'active_from' => now()->subYear()->toDateString(),
            'active_to' => null,
        ];
    }
}

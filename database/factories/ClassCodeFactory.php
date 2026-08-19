<?php

namespace Database\Factories;

use App\Models\ClassCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassCode>
 */
class ClassCodeFactory extends Factory
{
    protected $model = ClassCode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('F#B#')),
            'name' => fake()->unique()->word(),
            'name_fr' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Corridor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Corridor>
 */
class CorridorFactory extends Factory
{
    protected $model = Corridor::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('COR-???')),
            'name' => fake()->unique()->streetName().' corridor',
        ];
    }
}

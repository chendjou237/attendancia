<?php

namespace Database\Factories;

use App\Models\Corridor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'corridor_id' => Corridor::factory(),
            'code' => strtoupper(fake()->unique()->bothify('R-##')),
            'name' => 'Room '.fake()->unique()->numberBetween(1, 200),
        ];
    }
}

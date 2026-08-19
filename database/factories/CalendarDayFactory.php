<?php

namespace Database\Factories;

use App\Enums\DayType;
use App\Models\CalendarDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarDay>
 */
class CalendarDayFactory extends Factory
{
    protected $model = CalendarDay::class;

    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'day_type' => DayType::Teaching,
            'note' => null,
        ];
    }
}

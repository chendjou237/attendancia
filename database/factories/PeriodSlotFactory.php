<?php

namespace Database\Factories;

use App\Models\PeriodSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodSlot>
 */
class PeriodSlotFactory extends Factory
{
    protected $model = PeriodSlot::class;

    public function definition(): array
    {
        return [
            'day_of_week' => 1,
            'seq' => 1,
            'start_time' => '07:30:00',
            'end_time' => '08:25:00',
            'is_break' => false,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => null,
        ];
    }

    public function break(): static
    {
        return $this->state(fn () => ['is_break' => true]);
    }
}

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
            // Unique by default so unrelated factory calls in the same
            // test don't collide on (day_of_week, seq, valid_from) —
            // tests that care about a specific seq override it explicitly.
            // Capped at 255: seq is an unsignedTinyInteger column, and
            // SQLite (unlike MySQL) doesn't enforce that range, so this
            // only surfaces when the same test also runs against MySQL.
            'seq' => fake()->unique()->numberBetween(1, 255),
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

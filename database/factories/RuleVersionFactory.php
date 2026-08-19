<?php

namespace Database\Factories;

use App\Models\RuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleVersion>
 */
class RuleVersionFactory extends Factory
{
    protected $model = RuleVersion::class;

    public function definition(): array
    {
        return [
            // §5 current values.
            'grace_late_minutes' => 10,
            'grace_early_minutes' => 15,
            'pair_window_before_minutes' => 15,
            'pair_window_after_minutes' => 15,
            'min_scan_gap_seconds' => 30,
            'min_session_minutes' => 10,
            'hours_per_period' => 1.00,
            'valid_from' => now()->subYear()->toDateString(),
            'created_by' => null,
            'note' => null,
        ];
    }
}

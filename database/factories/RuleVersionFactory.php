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

    private static int $sequence = 0;

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
            // Unique by default (valid_from is a unique column) so
            // unrelated factory calls don't collide. A monotonic
            // sequence rather than fake()->unique() — the latter
            // resets per test (fresh Faker instance from Laravel's
            // per-test refreshApplication()) and can pick the same
            // random date across two different tests, which is a
            // real collision once the DB row from the first isn't
            // rolled back yet (nested/afterEach ordering) or the
            // two tests share a transaction.
            'valid_from' => now()->subYears(3)->addDays(self::$sequence++)->format('Y-m-d'),
            'created_by' => null,
            'note' => null,
        ];
    }
}

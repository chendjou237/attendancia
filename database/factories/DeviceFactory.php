<?php

namespace Database\Factories;

use App\Models\Corridor;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'corridor_id' => Corridor::factory(),
            'serial' => fake()->unique()->numerify('DEV#######'),
            'model' => fake()->randomElement(['DS-K1A8603', 'DS-K1T8005EFX']),
            'ip' => fake()->localIpv4(),
            'is_active' => true,
            'firmware' => 'V1.2.3',
            'last_seen_at' => now(),
            'last_time_offset_seconds' => 0,
        ];
    }
}

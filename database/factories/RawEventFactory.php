<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\RawEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawEvent>
 */
class RawEventFactory extends Factory
{
    protected $model = RawEvent::class;

    private static int $serialCounter = 1;

    public function definition(): array
    {
        return [
            'device_id' => null,
            'device_serial' => 'DEV0000001',
            'device_event_serial' => self::$serialCounter++,
            'biometric_id' => (string) fake()->numberBetween(1, 999999),
            'event_time_device' => now(),
            'event_time_server' => now(),
            'major_event_type' => 5,
            'sub_event_type' => 38,
            'payload_json' => null,
            'teacher_id' => null,
        ];
    }
}

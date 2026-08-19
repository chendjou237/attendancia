<?php

namespace Database\Factories;

use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableEntry>
 */
class TimetableEntryFactory extends Factory
{
    protected $model = TimetableEntry::class;

    public function definition(): array
    {
        return [
            'version_id' => TimetableVersion::factory(),
            'day_of_week' => 1,
            'slot_id' => PeriodSlot::factory(),
            'class_code_id' => ClassCode::factory(),
            'room_id' => Room::factory(),
        ];
    }
}

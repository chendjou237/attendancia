<?php

namespace Database\Factories;

use App\Enums\SessionState;
use App\Models\AttendanceSession;
use App\Models\ClassCode;
use App\Models\Corridor;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    protected $model = AttendanceSession::class;

    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'date' => now()->toDateString(),
            'class_code_id' => ClassCode::factory(),
            'room_id' => Room::factory(),
            'corridor_id' => Corridor::factory(),
            'first_slot_id' => PeriodSlot::factory(),
            'last_slot_id' => PeriodSlot::factory(),
            'scan_in_event_id' => null,
            'scan_out_event_id' => null,
            'state' => SessionState::Unpaired,
            'anomaly_code' => null,
        ];
    }
}

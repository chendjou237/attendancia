<?php

namespace Tests\Support;

use App\Models\ClassCode;
use App\Models\Corridor;
use App\Models\Device;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Shared scaffolding for the attendance engine test suite: one teacher
 * with one timetable entry in one corridor/room/device, ready for a
 * session to be built and paired against. Individual tests override
 * whatever piece the scenario is actually about.
 */
class AttendanceFixture
{
    public Corridor $corridor;

    public Corridor $wrongCorridor;

    public Room $room;

    public Device $device;

    public Device $wrongDevice;

    public ClassCode $classCode;

    public Teacher $teacher;

    public TimetableVersion $timetableVersion;

    public PeriodSlot $slot;

    public RuleVersion $rule;

    public CarbonInterface $date;

    /**
     * @param  array<int, array{0: string, 1: string, 2?: bool}>  $slotTimes  [start, end, isBreak] tuples, seq assigned in order
     * @param  array<string, mixed>  $ruleOverrides
     */
    public static function make(array $slotTimes = [['07:30:00', '08:25:00']], array $ruleOverrides = []): self
    {
        $f = new self;

        $f->corridor = Corridor::factory()->create();
        $f->wrongCorridor = Corridor::factory()->create();
        $f->room = Room::factory()->for($f->corridor)->create();
        $f->device = Device::factory()->for($f->corridor)->create();
        $f->wrongDevice = Device::factory()->for($f->wrongCorridor)->create();
        $f->classCode = ClassCode::factory()->create();
        $f->teacher = Teacher::factory()->create();
        $f->timetableVersion = TimetableVersion::factory()->for($f->teacher)->create();
        $f->date = Carbon::now()->next(Carbon::MONDAY);

        $slots = [];
        foreach ($slotTimes as $i => $time) {
            $slot = PeriodSlot::factory()->create([
                'day_of_week' => 1,
                'seq' => $i + 1,
                'start_time' => $time[0],
                'end_time' => $time[1],
                'is_break' => $time[2] ?? false,
            ]);
            $slots[] = $slot;

            if (! ($time[2] ?? false)) {
                TimetableEntry::factory()->for($f->timetableVersion, 'version')->create([
                    'day_of_week' => 1,
                    'slot_id' => $slot->id,
                    'class_code_id' => $f->classCode->id,
                    'room_id' => $f->room->id,
                ]);
            }
        }
        $f->slot = $slots[0];

        $f->rule = RuleVersion::factory()->create(array_merge([
            'grace_late_minutes' => 10,
            'grace_early_minutes' => 15,
            'pair_window_before_minutes' => 15,
            'pair_window_after_minutes' => 15,
            'min_scan_gap_seconds' => 30,
            'min_session_minutes' => 10,
        ], $ruleOverrides));

        return $f;
    }

    public function scanAt(string $time, ?Device $device = null): void
    {
        // Both set to the same instant: PairingEngine reads
        // event_time_device (falling back to event_time_server only
        // when the device timestamp is null), and a live scan has the
        // two only seconds apart in reality.
        $at = Carbon::parse($this->date->toDateString().' '.$time, config('attendance.timezone'))->utc();

        \App\Models\RawEvent::factory()->create([
            'device_id' => ($device ?? $this->device)->id,
            'teacher_id' => $this->teacher->id,
            'event_time_device' => $at,
            'event_time_server' => $at,
        ]);
    }
}

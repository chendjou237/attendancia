<?php

use App\Enums\PeriodStatus;
use App\Models\ClassCode;
use App\Models\Corridor;
use App\Models\Device;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RawEvent;
use App\Models\Room;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\Hikvision\AcsEventFetcher;
use App\Services\Hikvision\NightlyRecovery;

beforeEach(function () {
    config(['attendance.default_user' => 'admin', 'attendance.default_pass' => 'secret']);
});

/**
 * Same "fake the fetcher, not the HTTP call" approach as
 * BackfillHikvisionEventsTest — digest auth doesn't survive Http::fake().
 * Records how many devices it was asked to fetch for, since that's what
 * this file needs to prove (every active device gets backfilled).
 */
function fakeEmptyAcsEventFetcher(): object
{
    $fake = new class extends AcsEventFetcher
    {
        public array $calledForSerials = [];

        public function fetch($device, $user, $pass, $condition): array
        {
            $this->calledForSerials[] = $device->serial;

            return ['InfoList' => [], 'responseStatusStrg' => null];
        }
    };

    app()->instance(AcsEventFetcher::class, $fake);

    return $fake;
}

it('backfills every active device and skips inactive ones', function () {
    $fake = fakeEmptyAcsEventFetcher();

    $active1 = Device::factory()->for(Corridor::factory())->create(['is_active' => true, 'serial' => 'ACTIVE-1']);
    $active2 = Device::factory()->for(Corridor::factory())->create(['is_active' => true, 'serial' => 'ACTIVE-2']);
    Device::factory()->for(Corridor::factory())->create(['is_active' => false, 'serial' => 'INACTIVE-1']);

    (new NightlyRecovery)->run();

    expect($fake->calledForSerials)->toContain('ACTIVE-1', 'ACTIVE-2');
    expect($fake->calledForSerials)->not->toContain('INACTIVE-1');
});

// The gap this whole class exists to close: backfill alone only
// recovers raw_events. Without an automatic recompute, a scan that
// arrived via backfill for a date that already passed would sit
// forever as data with no period_result unless a human happened to
// re-run attendance:compute for that exact date.
it('turns an already-backfilled raw event from yesterday into a period_result, with no manual attendance:compute needed', function () {
    fakeEmptyAcsEventFetcher();

    $yesterday = Carbon\Carbon::yesterday();
    $corridor = Corridor::factory()->create();
    $room = Room::factory()->for($corridor)->create();
    $device = Device::factory()->for($corridor)->create(['is_active' => true]);
    $classCode = ClassCode::factory()->create();
    $teacher = Teacher::factory()->create();
    $version = TimetableVersion::factory()->for($teacher)->create(['valid_from' => $yesterday->clone()->subYear()]);
    $slot = PeriodSlot::factory()->create([
        'day_of_week' => $yesterday->dayOfWeek,
        'seq' => 1,
        'start_time' => '07:30:00',
        'end_time' => '08:25:00',
        'is_break' => false,
        'valid_from' => $yesterday->clone()->subYear(),
    ]);
    TimetableEntry::factory()->for($version, 'version')->create([
        'day_of_week' => $yesterday->dayOfWeek,
        'slot_id' => $slot->id,
        'class_code_id' => $classCode->id,
        'room_id' => $room->id,
    ]);
    RuleVersion::factory()->create(['valid_from' => $yesterday->clone()->subYear()]);

    $tz = config('attendance.timezone');
    RawEvent::factory()->create([
        'device_id' => $device->id,
        'teacher_id' => $teacher->id,
        'event_time_device' => Carbon\Carbon::parse($yesterday->toDateString().' 07:28:00', $tz)->utc(),
        'event_time_server' => Carbon\Carbon::parse($yesterday->toDateString().' 07:28:00', $tz)->utc(),
    ]);
    RawEvent::factory()->create([
        'device_id' => $device->id,
        'teacher_id' => $teacher->id,
        'event_time_device' => Carbon\Carbon::parse($yesterday->toDateString().' 08:24:00', $tz)->utc(),
        'event_time_server' => Carbon\Carbon::parse($yesterday->toDateString().' 08:24:00', $tz)->utc(),
    ]);

    expect(PeriodResult::where('teacher_id', $teacher->id)->whereDate('date', $yesterday->toDateString())->exists())->toBeFalse();

    (new NightlyRecovery)->run();

    $result = PeriodResult::where('teacher_id', $teacher->id)->whereDate('date', $yesterday->toDateString())->first();
    expect($result)->not->toBeNull();
    expect($result->status)->toBe(PeriodStatus::Present);
});

it('is safe to run with no active devices and nothing to compute', function () {
    fakeEmptyAcsEventFetcher();

    (new NightlyRecovery)->run(); // should not throw
})->throwsNoExceptions();

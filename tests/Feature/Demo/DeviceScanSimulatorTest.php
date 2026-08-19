<?php

use App\Models\Corridor;
use App\Models\Device;
use App\Models\RawEvent;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Services\Demo\DeviceScanSimulator;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;

function scanSimulator(): DeviceScanSimulator
{
    return new DeviceScanSimulator(new EventNormalizer, new EventProcessor);
}

it('creates a raw event resolving to the mapped teacher and updates device last_seen_at', function () {
    $device = Device::factory()->for(Corridor::factory())->create(['last_seen_at' => now()->subDay()]);
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->create(['teacher_id' => $teacher->id, 'biometric_id' => '1234', 'valid_from' => now()->subMonth()]);

    $event = scanSimulator()->scan($device, '1234');

    expect($event->teacher_id)->toBe($teacher->id);
    expect($event->major_event_type)->toBe(5);
    expect($event->sub_event_type)->toBe(38);
    $device->refresh();
    expect($device->last_seen_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('assigns non-colliding serials across repeated calls, even after a prior batch', function () {
    $device = Device::factory()->for(Corridor::factory())->create();
    RawEvent::factory()->create(['device_id' => $device->id, 'device_serial' => $device->serial, 'device_event_serial' => 500]);

    $simulator = scanSimulator();
    $first = $simulator->scan($device, '1111');
    $second = $simulator->scan($device, '2222');

    expect($first->device_event_serial)->toBeGreaterThan(500);
    expect($second->device_event_serial)->toBeGreaterThan($first->device_event_serial);
});

it('leaves an unmapped biometric_id unresolved rather than guessing', function () {
    $device = Device::factory()->for(Corridor::factory())->create();

    $event = scanSimulator()->scan($device, 'no-such-mapping');

    expect($event->teacher_id)->toBeNull();
    expect($event->biometric_id)->toBe('no-such-mapping');
});

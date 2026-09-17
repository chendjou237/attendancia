<?php

use App\Filament\Pages\DeviceMonitor;
use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Models\Corridor;
use App\Models\Device;
use App\Models\RawEvent;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function loginAsForMonitor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

it('is reachable by admin and officer, blocked for principal and hr', function (string $role, bool $allowed) {
    loginAsForMonitor($role);

    $response = $this->get(DeviceMonitor::getUrl());

    $allowed ? $response->assertSuccessful() : $response->assertForbidden();
})->with([
    ['admin', true],
    ['officer', true],
    ['principal', false],
    ['hr', false],
]);

it('classifies device status from last_seen_at against the configured idle timeout', function () {
    loginAsForMonitor('admin');
    config(['attendance.idle_timeout' => 90]);

    $corridor = Corridor::factory()->create();
    $online = Device::factory()->for($corridor)->create(['last_seen_at' => now()->subSeconds(10)]);
    $warning = Device::factory()->for(Corridor::factory())->create(['last_seen_at' => now()->subMinutes(30)]);
    $offlineStale = Device::factory()->for(Corridor::factory())->create(['last_seen_at' => now()->subHours(3)]);
    $offlineNever = Device::factory()->for(Corridor::factory())->create(['last_seen_at' => null]);

    $component = Livewire::test(DeviceMonitor::class);
    $statuses = $component->get('devices')->keyBy('id')->map(fn ($d) => $d['status']);

    expect($statuses[$online->id])->toBe('online');
    expect($statuses[$warning->id])->toBe('warning');
    expect($statuses[$offlineStale->id])->toBe('offline');
    expect($statuses[$offlineNever->id])->toBe('offline');
});

it('only offers currently-enrolled teachers for the simulate-scan form', function () {
    loginAsForMonitor('admin');

    $active = Teacher::factory()->create(['full_name' => 'Active Teacher']);
    TeacherBiometricId::factory()->create(['teacher_id' => $active->id, 'biometric_id' => '1001', 'valid_from' => now()->subMonth(), 'valid_to' => null]);

    $expired = Teacher::factory()->create(['full_name' => 'Expired Teacher']);
    TeacherBiometricId::factory()->create(['teacher_id' => $expired->id, 'biometric_id' => '1002', 'valid_from' => now()->subYear(), 'valid_to' => now()->subMonth()]);

    $names = Livewire::test(DeviceMonitor::class)->get('enrolledTeachers')->map(fn ($e) => $e->teacher->full_name);

    expect($names)->toContain('Active Teacher');
    expect($names)->not->toContain('Expired Teacher');
});

it('simulating a scan creates a raw event for the selected device and teacher', function () {
    loginAsForMonitor('officer');

    $device = Device::factory()->for(Corridor::factory())->create();
    $teacher = Teacher::factory()->create();
    $enrolment = TeacherBiometricId::factory()->create(['teacher_id' => $teacher->id, 'biometric_id' => '2001', 'valid_from' => now()->subMonth()]);

    Livewire::test(DeviceMonitor::class)
        ->set('selectedDeviceId', $device->id)
        ->set('selectedEnrolmentId', $enrolment->id)
        ->call('simulateScan');

    $event = RawEvent::where('device_id', $device->id)->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->teacher_id)->toBe($teacher->id);
});

it('shows the most recent raw events, newest first', function () {
    loginAsForMonitor('admin');

    $device = Device::factory()->for(Corridor::factory())->create();
    $older = RawEvent::factory()->create(['device_id' => $device->id, 'device_serial' => $device->serial, 'device_event_serial' => 1, 'event_time_server' => now()->subMinutes(5)]);
    $newer = RawEvent::factory()->create(['device_id' => $device->id, 'device_serial' => $device->serial, 'device_event_serial' => 2, 'event_time_server' => now()]);

    $events = Livewire::test(DeviceMonitor::class)->get('recentEvents');

    expect($events->first()->id)->toBe($newer->id);
    expect($events->last()->id)->toBe($older->id);
});

// The fleet is mixed: DS-K1A8603 (fingerprint only) and DS-K1T8005EFX
// (fingerprint + proximity card). Showing the model tells an Officer
// which corridors have a card reader a teacher might badge on and
// wrongly believe they checked in.
it('shows each terminal model on the device card', function () {
    loginAsForMonitor('officer');

    Device::factory()->for(Corridor::factory())->create([
        'serial' => 'DEV0009001',
        'model' => 'DS-K1T8005EFX',
    ]);

    Livewire::test(DeviceMonitor::class)->assertSee('DS-K1T8005EFX');
});

it('omits the model line for a device whose model was never recorded', function () {
    loginAsForMonitor('officer');

    Device::factory()->for(Corridor::factory())->create([
        'serial' => 'DEV0009002',
        'model' => null,
    ]);

    Livewire::test(DeviceMonitor::class)
        ->assertSee('DEV0009002')
        ->assertDontSee('DS-K1');
});

// The admin form is where a model gets recorded in the first place.
it('saves the terminal model through the device form', function () {
    loginAsForMonitor('admin');

    $corridor = Corridor::factory()->create();

    Livewire::test(CreateDevice::class)
        ->fillForm([
            'corridor_id' => $corridor->id,
            'serial' => 'DEV0009003',
            'model' => 'DS-K1T8005EFX',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Device::where('serial', 'DEV0009003')->value('model'))->toBe('DS-K1T8005EFX');
});

// 25 rows was under ten actual people: every tap also emits companion
// events the device reports under other sub-types, so the feed filled
// before it could answer "did this teacher scan, and when?".
it('shows a longer feed than the old 25 rows by default', function () {
    loginAsForMonitor('admin');

    $device = Device::factory()->for(Corridor::factory())->create();
    for ($i = 1; $i <= 40; $i++) {
        RawEvent::factory()->create([
            'device_id' => $device->id,
            'device_serial' => $device->serial,
            'device_event_serial' => $i,
        ]);
    }

    expect(Livewire::test(DeviceMonitor::class)->get('recentEvents'))->toHaveCount(40);
});

it('lets the viewer pick how many rows to show', function () {
    loginAsForMonitor('admin');

    $device = Device::factory()->for(Corridor::factory())->create();
    for ($i = 1; $i <= 30; $i++) {
        RawEvent::factory()->create([
            'device_id' => $device->id,
            'device_serial' => $device->serial,
            'device_event_serial' => $i,
        ]);
    }

    $component = Livewire::test(DeviceMonitor::class)->set('feedLimit', 25);

    expect($component->get('recentEvents'))->toHaveCount(25);
});

// Livewire properties come from the browser: an arbitrary limit must not
// become an unbounded query against an append-only table.
it('ignores a row count it does not offer', function () {
    loginAsForMonitor('admin');

    $component = Livewire::test(DeviceMonitor::class)->set('feedLimit', 999999);

    expect($component->instance()->resolvedFeedLimit())->toBe(DeviceMonitor::DEFAULT_FEED_LIMIT);
});

it('can hide everything no fingerprint resolved to a teacher', function () {
    loginAsForMonitor('admin');

    $device = Device::factory()->for(Corridor::factory())->create();
    $teacher = Teacher::factory()->create();
    $identified = RawEvent::factory()->create([
        'device_id' => $device->id, 'device_serial' => $device->serial,
        'device_event_serial' => 1, 'teacher_id' => $teacher->id,
    ]);
    RawEvent::factory()->create([
        'device_id' => $device->id, 'device_serial' => $device->serial,
        'device_event_serial' => 2, 'teacher_id' => null,
    ]);

    $component = Livewire::test(DeviceMonitor::class);
    expect($component->get('recentEvents'))->toHaveCount(2);

    $component->set('onlyIdentified', true);
    expect($component->get('recentEvents')->pluck('id')->all())->toBe([$identified->id]);
});

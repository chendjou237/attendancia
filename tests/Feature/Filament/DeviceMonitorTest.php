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
use Illuminate\Support\Carbon;
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

// --- Case-by-case lookup: date, teacher, device and search filters ---

function scanFor(array $attributes, string $localTime): RawEvent
{
    $at = Carbon::parse($localTime, config('attendance.timezone'))->utc();

    return RawEvent::factory()->create(array_merge([
        'event_time_device' => $at,
        'event_time_server' => $at,
    ], $attributes));
}

it('filters the feed by the school-local day, not the UTC one', function () {
    loginAsForMonitor('admin');
    // 23:30 local on the 29th is still the 29th; 00:30 on the 30th is the
    // 30th — though in UTC both sit on the 29th.
    $lateEvening = scanFor([], '2026-09-29 23:30:00');
    scanFor([], '2026-09-30 00:30:00');

    $events = Livewire::test(DeviceMonitor::class)->set('feedDate', '2026-09-29')->get('recentEvents');

    expect($events->pluck('id')->all())->toBe([$lateEvening->id]);
});

it('reads a past day earliest scan first', function () {
    loginAsForMonitor('admin');
    $late = scanFor([], '2026-09-29 15:00:00');
    $early = scanFor([], '2026-09-29 07:29:00');

    $events = Livewire::test(DeviceMonitor::class)->set('feedDate', '2026-09-29')->get('recentEvents');

    expect($events->pluck('id')->all())->toBe([$early->id, $late->id]);
});

it('filters by teacher, including scans of a duplicate merged into them', function () {
    loginAsForMonitor('admin');
    $teacher = Teacher::factory()->create();
    $duplicate = Teacher::factory()->create();
    $duplicate->forceFill(['merged_into_id' => $teacher->id])->save();
    $own = scanFor(['teacher_id' => $teacher->id], '2026-09-29 07:29:00');
    $merged = scanFor(['teacher_id' => $duplicate->id], '2026-09-29 09:23:00');
    scanFor(['teacher_id' => Teacher::factory()->create()->id], '2026-09-29 08:00:00');

    $monitor = Livewire::test(DeviceMonitor::class)
        ->set('feedDate', '2026-09-29')
        ->set('feedTeacherId', (string) $teacher->id);

    expect($monitor->get('recentEvents')->pluck('id')->all())->toBe([$own->id, $merged->id])
        ->and($monitor->get('feedTeachers')->pluck('id'))->not->toContain($duplicate->id);
});

it('searches by teacher name and by raw fingerprint id', function () {
    loginAsForMonitor('admin');
    $ahanda = Teacher::factory()->create(['full_name' => 'AHANDA']);
    $byName = scanFor(['teacher_id' => $ahanda->id], '2026-09-29 07:29:00');
    $unmatched = scanFor(['teacher_id' => null, 'biometric_id' => '90417'], '2026-09-29 08:00:00');

    $monitor = Livewire::test(DeviceMonitor::class)->set('feedDate', '2026-09-29');

    expect($monitor->set('feedSearch', 'ahan')->get('recentEvents')->pluck('id')->all())->toBe([$byName->id])
        ->and($monitor->set('feedSearch', '9041')->get('recentEvents')->pluck('id')->all())->toBe([$unmatched->id]);
});

it('filters by device', function () {
    loginAsForMonitor('admin');
    $a = Device::factory()->for(Corridor::factory())->create();
    $b = Device::factory()->for(Corridor::factory())->create();
    $onA = scanFor(['device_id' => $a->id, 'device_serial' => $a->serial], '2026-09-29 07:29:00');
    scanFor(['device_id' => $b->id, 'device_serial' => $b->serial], '2026-09-29 07:30:00');

    $events = Livewire::test(DeviceMonitor::class)
        ->set('feedDate', '2026-09-29')
        ->set('feedDeviceId', (string) $a->id)
        ->get('recentEvents');

    expect($events->pluck('id')->all())->toBe([$onA->id]);
});

it('pre-fills teacher and date from the URL (the Exception Queue link)', function () {
    loginAsForMonitor('admin');
    $teacher = Teacher::factory()->create();
    $scan = scanFor(['teacher_id' => $teacher->id], '2026-09-29 07:29:00');

    $events = Livewire::withQueryParams(['teacher' => (string) $teacher->id, 'date' => '2026-09-29'])
        ->test(DeviceMonitor::class)
        ->assertSet('feedDate', '2026-09-29')
        ->get('recentEvents');

    expect($events->pluck('id')->all())->toBe([$scan->id]);
});

it('falls back to today on a date or id it cannot use', function (string $field, string $value) {
    loginAsForMonitor('admin');
    $today = RawEvent::factory()->create();

    $monitor = Livewire::test(DeviceMonitor::class)->set($field, $value);

    expect($monitor->get('recentEvents')->pluck('id')->all())->toBe([$today->id])
        ->and($monitor->instance()->isViewingToday())->toBeTrue();
})->with([
    'garbage date' => ['feedDate', 'abc'],
    'impossible date' => ['feedDate', '2026-02-31'],
    'garbage teacher' => ['feedTeacherId', 'x'],
    'unknown device' => ['feedDeviceId', '999999'],
]);

it('reset brings the feed back to today, unfiltered', function () {
    loginAsForMonitor('admin');

    Livewire::test(DeviceMonitor::class)
        ->set('feedDate', '2026-09-29')
        ->set('feedSearch', 'x')
        ->call('resetFeedFilters')
        ->assertSet('feedSearch', '')
        ->assertSet('feedDate', now(config('attendance.timezone'))->toDateString());
});

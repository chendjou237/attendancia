<?php

use App\Models\Corridor;
use App\Models\Device;
use App\Models\RawEvent;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

function device(): Device
{
    return Device::factory()->for(Corridor::factory())->create(['serial' => 'DEV0001234']);
}

function streamPayload(array $acsOverrides = []): array
{
    return [
        'ipAddress' => '192.168.1.64',
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => array_merge([
            'majorEventType' => 5,
            'subEventType' => 38,
            'serialNo' => 1042,
            'employeeNoString' => '7',
        ], $acsOverrides),
    ];
}

it('updates device liveness on every event, including a heartbeat', function () {
    $d = device();
    $normalizer = new EventNormalizer;

    (new EventProcessor)->handle($normalizer->fromStreamPayload(['eventType' => 'videoloss', 'dateTime' => '2026-08-18T09:14:02+01:00']), $d, 'stream');

    $d->refresh();
    expect($d->last_seen_at)->not->toBeNull();
    expect(RawEvent::count())->toBe(0);
});

it('ignores a device/network system event (majorEventType 2 or 3) and stores nothing', function () {
    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['majorEventType' => 3]));

    (new EventProcessor)->handle($event, $d, 'stream');

    expect(RawEvent::count())->toBe(0);
});

it('stores a passed fingerprint scan and resolves the teacher from biometric_id', function () {
    $d = device();
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($teacher)->create(['biometric_id' => '7', 'valid_from' => '2020-01-01']);

    $event = (new EventNormalizer)->fromStreamPayload(streamPayload());
    (new EventProcessor)->handle($event, $d, 'stream');

    $raw = RawEvent::first();
    expect($raw)->not->toBeNull();
    expect($raw->sub_event_type)->toBe(38);
    expect($raw->teacher_id)->toBe($teacher->id);
});

it('stores a passed scan with no teacher_id when biometric_id matches nobody', function () {
    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['employeeNoString' => 'unknown-id']));

    (new EventProcessor)->handle($event, $d, 'stream');

    $raw = RawEvent::first();
    expect($raw)->not->toBeNull();
    expect($raw->teacher_id)->toBeNull();
    expect($raw->biometric_id)->toBe('unknown-id');
});

// §6: subEventType 49 is still stored — unmatched/failed scans are the
// most valuable diagnostic data in the system — but never resolved to
// a teacher, since the verification failed.
it('stores a failed scan (subEventType 49) without attempting teacher resolution', function () {
    $d = device();
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($teacher)->create(['biometric_id' => '7', 'valid_from' => '2020-01-01']);

    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['subEventType' => 49]));
    (new EventProcessor)->handle($event, $d, 'stream');

    $raw = RawEvent::first();
    expect($raw)->not->toBeNull();
    expect($raw->sub_event_type)->toBe(49);
    expect($raw->teacher_id)->toBeNull();
});

// Open question #2 (§13): unrecognised sub-types (card/face) still get
// stored so the codes can be read off later, just not resolved.
it('stores an event with an unrecognised sub_event_type for later discovery', function () {
    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['subEventType' => 75]));

    (new EventProcessor)->handle($event, $d, 'stream');

    $raw = RawEvent::first();
    expect($raw)->not->toBeNull();
    expect($raw->sub_event_type)->toBe(75);
    expect($raw->teacher_id)->toBeNull();
});

// §7.2: "the unique index makes it idempotent" — a re-delivered stream
// event or an overlapping backfill window is a no-op, not a duplicate.
it('is idempotent: processing the same device_serial + device_event_serial twice stores one row', function () {
    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload());

    $processor = new EventProcessor;
    $processor->handle($event, $d, 'stream');
    $processor->handle($event, $d, 'backfill');

    expect(RawEvent::count())->toBe(1);
});

it('resolves the teacher using who held the biometric id at the event time, not today', function () {
    $d = device();
    $oldHolder = Teacher::factory()->create();
    $newHolder = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($oldHolder)->create(['biometric_id' => '7', 'valid_from' => '2020-01-01', 'valid_to' => '2026-01-01']);
    TeacherBiometricId::factory()->for($newHolder)->create(['biometric_id' => '7', 'valid_from' => '2026-01-02', 'valid_to' => null]);

    // The stream payload's dateTime is 2026-08-18 — after the reassignment.
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload());
    (new EventProcessor)->handle($event, $d, 'stream');

    expect(RawEvent::first()->teacher_id)->toBe($newHolder->id);
});

it('does not store anything for a majorEventType 5 event with no serialNo', function () {
    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload([
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 38, 'employeeNoString' => '7'],
    ]);

    (new EventProcessor)->handle($event, $d, 'stream');

    expect(RawEvent::count())->toBe(0);
});

// The DS-K1T8005EFX adds a 125kHz EM proximity card reader. Card
// verification passes as majorEventType 5 / subEventType 1 — a
// recognised code that attendance deliberately refuses, because a card
// can be lent or cloned and these records become payable hours. See
// tests/Feature/Attendance/CardVerificationTest.php for the end-to-end
// guarantee that one never becomes a Present.
it('stores a card scan (subEventType 1) without attempting teacher resolution', function () {
    $d = device();
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($teacher)->create(['biometric_id' => '7', 'valid_from' => '2020-01-01', 'valid_to' => null]);

    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['subEventType' => 1]));
    (new EventProcessor)->handle($event, $d, 'stream');

    $raw = RawEvent::first();
    expect($raw)->not->toBeNull()
        ->and($raw->sub_event_type)->toBe(1)
        // Resolvable — the mapping exists and matches — and deliberately
        // left unresolved anyway.
        ->and($raw->teacher_id)->toBeNull();
});

it('logs a card scan as an ignored card, not as an unrecognised sub-type', function () {
    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('attendance')->andReturn($spy);
    Log::shouldReceive('debug')->never();

    $d = device();
    $event = (new EventNormalizer)->fromStreamPayload(streamPayload(['subEventType' => 1]));

    (new EventProcessor)->handle($event, $d, 'stream');

    $spy->shouldHaveReceived('info');
});

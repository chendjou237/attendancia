<?php

use App\Services\Hikvision\EventNormalizer;
use Illuminate\Support\Facades\Log;

it('normalizes the documented stream payload shape exactly', function () {
    $payload = [
        'ipAddress' => '192.168.1.64',
        'portNo' => 80,
        'channelID' => 1,
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'activePostCount' => 1,
        'eventType' => 'AccessControllerEvent',
        'eventState' => 'active',
        'eventDescription' => 'Access Controller Event',
        'AccessControllerEvent' => [
            'deviceName' => 'Access Controller',
            'majorEventType' => 5,
            'subEventType' => 38,
            'serialNo' => 1042,
            'employeeNoString' => '1',
            'currentVerifyMode' => 'cardOrFaceOrFp',
        ],
    ];

    $event = (new EventNormalizer)->fromStreamPayload($payload);

    expect($event->deviceEventSerial)->toBe(1042);
    expect($event->biometricId)->toBe('1');
    expect($event->majorEventType)->toBe(5);
    expect($event->subEventType)->toBe(38);
    expect($event->isHeartbeat())->toBeFalse();
    // 09:14:02+01:00 is 08:14:02 UTC — the device's own offset, not assumed.
    expect($event->eventTimeDevice->toDateTimeString())->toBe('2026-08-18 08:14:02');
});

it('treats a videoloss payload with no AccessControllerEvent as a heartbeat', function () {
    $event = (new EventNormalizer)->fromStreamPayload([
        'ipAddress' => '192.168.1.64',
        'eventType' => 'videoloss',
        'dateTime' => '2026-08-18T09:14:02+01:00',
    ]);

    expect($event->isHeartbeat())->toBeTrue();
    expect($event->majorEventType)->toBeNull();
    expect($event->deviceEventSerial)->toBeNull();
});

it('trims biometric_id and treats blank as null', function () {
    $withSpaces = (new EventNormalizer)->fromStreamPayload([
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 38, 'serialNo' => 1, 'employeeNoString' => '  7  '],
    ]);
    expect($withSpaces->biometricId)->toBe('7');

    $blank = (new EventNormalizer)->fromStreamPayload([
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 49, 'serialNo' => 2, 'employeeNoString' => '   '],
    ]);
    expect($blank->biometricId)->toBeNull();
});

it('parses an AcsEvent search record using the documented major/minor/time naming', function () {
    $event = (new EventNormalizer)->fromAcsEventRecord([
        'time' => '2026-08-18T09:14:02+01:00',
        'major' => 5,
        'minor' => 38,
        'serialNo' => 2001,
        'employeeNoString' => '1',
    ]);

    expect($event->majorEventType)->toBe(5);
    expect($event->subEventType)->toBe(38);
    expect($event->deviceEventSerial)->toBe(2001);
});

it('falls back to the stream field naming for an AcsEvent record if that is what this firmware sends', function () {
    $event = (new EventNormalizer)->fromAcsEventRecord([
        'dateTime' => '2026-08-18T09:14:02+01:00',
        'majorEventType' => 5,
        'subEventType' => 38,
        'serialNo' => 2002,
        'employeeNoString' => '1',
    ]);

    expect($event->majorEventType)->toBe(5);
    expect($event->subEventType)->toBe(38);
});

it('logs a warning when an AcsEvent record is missing an expected field under either naming', function () {
    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('attendance')->andReturn($spy);

    (new EventNormalizer)->fromAcsEventRecord(['serialNo' => 2003]);

    $spy->shouldHaveReceived('warning');
});

it('returns null and logs a warning for an unparseable timestamp, rather than substituting now()', function () {
    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('attendance')->andReturn($spy);

    $event = (new EventNormalizer)->fromStreamPayload([
        'dateTime' => 'not-a-real-timestamp',
        'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 38, 'serialNo' => 1, 'employeeNoString' => '1'],
    ]);

    expect($event->eventTimeDevice)->toBeNull();
    $spy->shouldHaveReceived('warning');
});

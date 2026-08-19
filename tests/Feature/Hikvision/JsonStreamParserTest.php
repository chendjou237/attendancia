<?php

use App\Services\Hikvision\JsonStreamParser;
use Illuminate\Support\Facades\Log;

it('parses a complete object delivered in a single chunk', function () {
    $parser = new JsonStreamParser;

    $objects = $parser->feed('{"a":1}');

    expect($objects)->toHaveCount(1);
    expect(json_decode($objects[0], true))->toBe(['a' => 1]);
});

it('parses an object split across three chunks, byte by byte', function () {
    $parser = new JsonStreamParser;
    $json = '{"ipAddress":"192.168.1.64","AccessControllerEvent":{"majorEventType":5,"subEventType":38}}';

    $collected = [];
    foreach (str_split($json) as $byte) {
        $collected = [...$collected, ...$parser->feed($byte)];
    }

    expect($collected)->toHaveCount(1);
    $decoded = json_decode($collected[0], true);
    expect($decoded['ipAddress'])->toBe('192.168.1.64');
    expect($decoded['AccessControllerEvent']['majorEventType'])->toBe(5);
});

// §7.2 finding: strpos($buffer, '}') would close on the nested
// AccessControllerEvent object first and yield truncated garbage.
it('does not truncate at the first closing brace of a nested object', function () {
    $parser = new JsonStreamParser;
    $json = '{"outer":true,"AccessControllerEvent":{"nested":1},"after":"still here"}';

    $objects = $parser->feed($json);

    expect($objects)->toHaveCount(1);
    $decoded = json_decode($objects[0], true);
    expect($decoded['after'])->toBe('still here');
});

it('parses two complete objects delivered in one chunk', function () {
    $parser = new JsonStreamParser;

    $objects = $parser->feed('{"a":1}{"b":2}');

    expect($objects)->toHaveCount(2);
    expect(json_decode($objects[0], true))->toBe(['a' => 1]);
    expect(json_decode($objects[1], true))->toBe(['b' => 2]);
});

it('does not let a brace inside a string value affect depth counting', function () {
    $parser = new JsonStreamParser;

    $objects = $parser->feed('{"deviceName":"Gate {A}","ok":true}');

    expect($objects)->toHaveCount(1);
    expect(json_decode($objects[0], true))->toBe(['deviceName' => 'Gate {A}', 'ok' => true]);
});

it('does not let an escaped quote inside a string value end the string early', function () {
    $parser = new JsonStreamParser;

    $objects = $parser->feed('{"note":"he said \"hi\"","ok":true}');

    expect($objects)->toHaveCount(1);
    expect(json_decode($objects[0], true))->toBe(['note' => 'he said "hi"', 'ok' => true]);
});

it('ignores a leading partial fragment when the stream is joined mid-object', function () {
    $parser = new JsonStreamParser;

    // The first bytes ever received are the tail of some object that
    // started before this connection began listening.
    $objects = $parser->feed('ge":1}{"real":"object"}');

    expect($objects)->toHaveCount(1);
    expect(json_decode($objects[0], true))->toBe(['real' => 'object']);
});

it('ignores multipart boundary and header lines interleaved between objects', function () {
    $parser = new JsonStreamParser;

    $stream = "--MIME_boundary\r\n"
        ."Content-Type: application/json\r\n"
        ."Content-Length: 13\r\n\r\n"
        .'{"a":1}'
        ."\r\n--MIME_boundary\r\n"
        ."Content-Type: application/json\r\n\r\n"
        .'{"b":2}'
        ."\r\n--MIME_boundary--\r\n";

    $objects = $parser->feed($stream);

    expect($objects)->toHaveCount(2);
    expect(json_decode($objects[0], true))->toBe(['a' => 1]);
    expect(json_decode($objects[1], true))->toBe(['b' => 2]);
});

it('discards an oversized capture without throwing, then resyncs on the next object', function () {
    Log::spy();
    $parser = new JsonStreamParser;

    // Never closes — a pathological/malformed capture.
    $garbage = '{"a":"'.str_repeat('x', 1_100_000);
    $objects = $parser->feed($garbage);
    expect($objects)->toBeArray()->toBeEmpty();

    Log::shouldHaveReceived('warning')->once();

    // The parser must have abandoned the bad capture and be ready for
    // the next real object, not stuck in a corrupted state.
    $recovered = $parser->feed('{"clean":true}');
    expect($recovered)->toHaveCount(1);
    expect(json_decode($recovered[0], true))->toBe(['clean' => true]);
});

it('handles a realistic AccessControllerEvent payload end to end, split across chunks', function () {
    $parser = new JsonStreamParser;
    $payload = json_encode([
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
    ]);

    $mime = "--boundary\r\nContent-Type: application/json\r\n\r\n{$payload}\r\n--boundary\r\n";
    $chunks = str_split($mime, 17); // arbitrary, non-aligned chunk size

    $collected = [];
    foreach ($chunks as $chunk) {
        $collected = [...$collected, ...$parser->feed($chunk)];
    }

    expect($collected)->toHaveCount(1);
    $decoded = json_decode($collected[0], true);
    expect($decoded['AccessControllerEvent']['employeeNoString'])->toBe('1');
    expect($decoded['AccessControllerEvent']['serialNo'])->toBe(1042);
});

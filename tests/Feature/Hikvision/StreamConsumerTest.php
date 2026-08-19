<?php

use App\Services\Hikvision\JsonStreamParser;
use App\Services\Hikvision\StreamConsumer;
use App\Services\Hikvision\StreamIdleTimeoutException;
use Tests\Support\FakeChunkedStream;

it('feeds every chunk to the parser and calls onObject for each complete object', function () {
    $stream = new FakeChunkedStream(['{"a":1}', '{"b":2}']);
    $consumer = new StreamConsumer(new JsonStreamParser);

    $seen = [];
    $consumer->consume($stream, function (string $json) use (&$seen) {
        $seen[] = json_decode($json, true);
    }, idleTimeoutSeconds: 90, shouldStop: fn () => false);

    expect($seen)->toBe([['a' => 1], ['b' => 2]]);
});

it('stops as soon as shouldStop returns true, without reading the rest of the stream', function () {
    $stream = new FakeChunkedStream(['{"a":1}', '{"b":2}', '{"c":3}']);
    $consumer = new StreamConsumer(new JsonStreamParser);

    $seen = [];
    $reads = 0;
    $consumer->consume($stream, function (string $json) use (&$seen) {
        $seen[] = json_decode($json, true);
    }, idleTimeoutSeconds: 90, shouldStop: function () use (&$reads) {
        return $reads++ >= 1;
    });

    expect($seen)->toBe([['a' => 1]]);
});

it('does not throw on a brief empty read that stays under the idle timeout', function () {
    $stream = new FakeChunkedStream(['', '', '{"a":1}']);
    $consumer = new StreamConsumer(new JsonStreamParser);

    $seen = [];
    $consumer->consume($stream, function (string $json) use (&$seen) {
        $seen[] = json_decode($json, true);
    }, idleTimeoutSeconds: 90, shouldStop: fn () => false);

    expect($seen)->toBe([['a' => 1]]);
})->group('slow');

// The device's own keepalives mean sustained silence always means a
// half-open connection, never a quiet device.
it('throws StreamIdleTimeoutException after sustained silence beyond the idle timeout', function () {
    $stream = new FakeChunkedStream(array_fill(0, 20, ''));
    $consumer = new StreamConsumer(new JsonStreamParser);

    $consumer->consume($stream, fn () => null, idleTimeoutSeconds: 1, shouldStop: fn () => false);
})->throws(StreamIdleTimeoutException::class)->group('slow');

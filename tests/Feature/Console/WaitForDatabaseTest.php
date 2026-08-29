<?php

use App\Services\DatabaseReadiness;
use Illuminate\Support\Sleep;

beforeEach(function () {
    // Every wait in here is a fake one — the point of these tests is the
    // give-up policy and the heartbeat cadence, not real elapsed time.
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

it('returns immediately when the database is already up', function () {
    $this->artisan('db:wait')
        ->expectsOutputToContain('The database is up.')
        ->assertSuccessful();

    Sleep::assertNeverSlept();
});

// The connection name is deliberately one that isn't configured: it makes
// every attempt fail instantly and deterministically, where pointing at a
// dead TCP port would make this test's runtime depend on the host's
// connect-refused behaviour.
it('gives up after the timeout rather than blocking forever', function () {
    $this->artisan('db:wait', ['--connection' => 'nope', '--timeout' => 30])
        ->expectsOutputToContain('never became reachable')
        ->assertFailed();
});

// The regression that shipping this without a real server nearly hid: the
// first failed poll used to disconnect() the connection, which nulls the PDO
// but leaves the Connection cached — so the *second* poll got that null back
// with no exception and reported a dead server as ready. Any test that polls
// only once, or that fakes canConnect(), passes right through it.
it('keeps reporting a configured but unreachable server as not ready, poll after poll', function () {
    config(['database.connections.unreachable' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nothing',
        'username' => 'nobody',
        'password' => '',
    ]]);

    $readiness = new DatabaseReadiness;

    expect($readiness->canConnect('unreachable'))->toBeFalse();
    expect($readiness->canConnect('unreachable'))->toBeFalse();
    expect($readiness->canConnect('unreachable'))->toBeFalse();

    expect($readiness->waitUntilReady(timeoutSeconds: 6, connection: 'unreachable'))->toBeFalse();
});

it('emits a heartbeat while it waits so a stalled boot is visible in the log', function () {
    $this->artisan('db:wait', ['--connection' => 'nope', '--timeout' => 40])
        ->expectsOutputToContain('Still waiting for the database... 16s elapsed')
        ->assertFailed();
});

it('stops polling the moment the database comes up', function () {
    $readiness = new class extends DatabaseReadiness
    {
        public int $attempts = 0;

        public function canConnect(?string $connection = null): bool
        {
            return ++$this->attempts >= 3;
        }
    };

    expect($readiness->waitUntilReady(timeoutSeconds: 300))->toBeTrue();
    expect($readiness->attempts)->toBe(3);

    // Two failed attempts, so two waits — not a third after success.
    Sleep::assertSleptTimes(2);
});

// A stop requested during the wait has to be honoured: on a post-power-cut
// boot this loop can legitimately be the only thing running for minutes,
// and the supervisor should get a clean exit rather than having to kill it.
it('abandons the wait when asked to stop', function () {
    $readiness = new class extends DatabaseReadiness
    {
        public function canConnect(?string $connection = null): bool
        {
            return false;
        }
    };

    expect($readiness->waitUntilReady(timeoutSeconds: 300, shouldStop: fn () => true))->toBeFalse();

    Sleep::assertNeverSlept();
});

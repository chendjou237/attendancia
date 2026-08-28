<?php

it('fails fast when no device with that serial exists', function () {
    $this->artisan('hikvision:stream', ['device' => 'NOPE'])
        ->expectsOutputToContain('No device with serial')
        ->assertFailed();
});

it('fails fast when no credentials are configured', function () {
    config(['attendance.default_user' => null, 'attendance.default_pass' => null]);
    $device = \App\Models\Device::factory()->for(\App\Models\Corridor::factory())->create(['serial' => 'DEV0001234']);

    $this->artisan('hikvision:stream', ['device' => $device->serial])
        ->expectsOutputToContain('No ISAPI credentials configured')
        ->assertFailed();
});

// The Windows target has no ext-pcntl, so this command has to survive
// having no signal mechanism at all. Before GracefulShutdown, the
// unguarded pcntl_async_signals() call fataled here on startup.
it('starts without signal handling instead of fataling on a build with neither mechanism', function () {
    $this->app->instance(\App\Services\Console\GracefulShutdown::class, new class extends \App\Services\Console\GracefulShutdown
    {
        protected function supportsPcntl(): bool
        {
            return false;
        }

        protected function supportsWindowsCtrlHandler(): bool
        {
            return false;
        }
    });

    $spy = Mockery::spy();
    \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('attendance')->andReturn($spy);

    // Reaching the device lookup at all is the assertion: the command got
    // past signal registration rather than dying inside it.
    $this->artisan('hikvision:stream', ['device' => 'NOPE'])
        ->expectsOutputToContain('No device with serial')
        ->assertFailed();

    $spy->shouldHaveReceived('warning');
});

it('waits for the database before touching it when asked', function () {
    $this->artisan('hikvision:stream', ['device' => 'NOPE', '--wait-for-db' => true])
        ->expectsOutputToContain('The database is up.')
        ->expectsOutputToContain('No device with serial')
        ->assertFailed();
});

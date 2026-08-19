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

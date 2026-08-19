<?php

use App\Models\Teacher;

it('fails with a clear message when the file does not exist', function () {
    $this->artisan('teachers:import', ['file' => '/tmp/does-not-exist-xyz.csv'])
        ->expectsOutputToContain('File not found')
        ->assertFailed();
});

it('imports a real file end to end', function () {
    $path = tempnam(sys_get_temp_dir(), 'import_cmd_').'.csv';
    file_put_contents($path, "staff_no,full_name,employment_type,active_from,biometric_id\nT-0001,Ngwa Fon Peter,hourly,2026-09-01,\n");

    $this->artisan('teachers:import', ['file' => $path])
        ->expectsOutputToContain('1 created')
        ->assertSuccessful();

    expect(Teacher::count())->toBe(1);
});

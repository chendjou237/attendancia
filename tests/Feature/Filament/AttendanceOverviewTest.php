<?php

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Filament\Widgets\AttendanceOverview;
use App\Models\AttendanceSession;
use App\Models\Device;
use App\Models\PeriodResult;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('counts pending exceptions and links to the exception queue', function () {
    $session = AttendanceSession::factory()->create(['state' => SessionState::Unpaired, 'anomaly_code' => SessionAnomaly::NoScanIn->value]);
    PeriodResult::factory()->create([
        'teacher_id' => $session->teacher_id,
        'session_id' => $session->id,
        'status' => PeriodStatus::Unpaired,
        'is_current' => true,
    ]);

    Livewire::test(AttendanceOverview::class)
        ->assertSee('Pending exceptions')
        ->assertSee('1');
});

it('counts present and absent results for today only', function () {
    PeriodResult::factory()->create(['status' => PeriodStatus::Present, 'is_current' => true, 'date' => today()]);
    PeriodResult::factory()->create(['status' => PeriodStatus::Absent, 'is_current' => true, 'date' => today()]);
    // Yesterday's results must not bleed into today's counts.
    PeriodResult::factory()->create(['status' => PeriodStatus::Present, 'is_current' => true, 'date' => today()->subDay()]);

    Livewire::test(AttendanceOverview::class)
        ->assertSee('Present today')
        ->assertSee('Absent today');

    $overview = new AttendanceOverview;
    $stats = (fn () => $this->getStats())->call($overview);

    expect($stats[1]->getValue())->toBe(1); // Present today
    expect($stats[2]->getValue())->toBe(1); // Absent today
});

it('flags a device silent for more than two hours', function () {
    Device::factory()->create(['is_active' => true, 'last_seen_at' => now()->subMinutes(10)]);
    Device::factory()->create(['is_active' => true, 'last_seen_at' => now()->subHours(3)]);

    $overview = new AttendanceOverview;
    $stats = (fn () => $this->getStats())->call($overview);

    expect($stats[3]->getValue())->toBe('1 / 2');
});

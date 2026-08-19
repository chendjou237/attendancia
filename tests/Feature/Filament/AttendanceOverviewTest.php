<?php

use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Filament\Widgets\AttendanceOverview;
use App\Models\AttendanceSession;
use App\Models\Device;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use App\Models\RuleVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

function overviewStats(): array
{
    $overview = new AttendanceOverview;

    return (fn () => $this->getStats())->call($overview);
}

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

it('counts present and absent results for the current month only', function () {
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);
    PeriodResult::factory()->create(['status' => PeriodStatus::Present, 'is_current' => true, 'date' => today(), 'rule_version_id' => $rule->id]);
    PeriodResult::factory()->create(['status' => PeriodStatus::Absent, 'is_current' => true, 'date' => today(), 'rule_version_id' => $rule->id]);
    // Last month's results must not bleed into this month's counts.
    PeriodResult::factory()->create(['status' => PeriodStatus::Present, 'is_current' => true, 'date' => today()->subMonthNoOverflow(), 'rule_version_id' => $rule->id]);

    Livewire::test(AttendanceOverview::class)
        ->assertSee('Present this month')
        ->assertSee('Absent this month')
        ->assertSee('Hours logged this month');

    $stats = overviewStats();

    expect($stats[1]->getValue())->toBe(1); // Present this month
    expect($stats[2]->getValue())->toBe(1); // Absent this month
    expect($stats[3]->getValue())->toBe(1.0); // Hours logged this month
});

it('flags a device silent for more than two hours', function () {
    Device::factory()->create(['is_active' => true, 'last_seen_at' => now()->subMinutes(10)]);
    Device::factory()->create(['is_active' => true, 'last_seen_at' => now()->subHours(3)]);

    $stats = overviewStats();

    expect($stats[4]->getValue())->toBe('1 / 2');
});

it('shows this month\'s report state and links to Monthly Reports', function () {
    MonthlyReport::factory()->create(['month' => today()->startOfMonth(), 'state' => ReportState::OfficerReviewed, 'snapshot_json' => ['totals' => []]]);

    $stats = overviewStats();

    expect($stats[5]->getValue())->toBe(ReportState::OfficerReviewed->getLabel());
});

it('shows "Not generated" when the current month has no report yet', function () {
    $stats = overviewStats();

    expect($stats[5]->getValue())->toBe('Not generated');
});

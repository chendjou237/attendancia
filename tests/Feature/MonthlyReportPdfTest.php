<?php

use App\Enums\ReportState;
use App\Models\MonthlyReport;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function loginAsForReportPdf(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function wholeReport(array $overrides = []): MonthlyReport
{
    return MonthlyReport::factory()->create(array_merge([
        'state' => ReportState::Draft,
        'snapshot_json' => [
            'generated_at' => now()->toIso8601String(),
            'hours_basis' => 'Each taught period counts as 1.00h.',
            'teachers' => [
                ['teacher_id' => 1, 'staff_no' => 'T-0001', 'full_name' => 'Ngwa Fon Peter', 'employment_type' => 'hourly', 'present' => 10, 'present_admin' => 0, 'absent' => 2, 'absent_justified' => 0, 'pending' => 0, 'total_periods' => 12, 'hours_taught' => 10.0],
                ['teacher_id' => 2, 'staff_no' => 'T-0002', 'full_name' => 'Achu Rebecca Manka', 'employment_type' => 'salaried', 'present' => 8, 'present_admin' => 0, 'absent' => 1, 'absent_justified' => 0, 'pending' => 1, 'total_periods' => 9, 'hours_taught' => 8.0],
            ],
            'totals' => [
                'present' => 18, 'present_admin' => 0, 'absent' => 3, 'absent_justified' => 0,
                'pending' => 1, 'payable_hours' => 10.0, 'oversight_hours' => 8.0,
            ],
            'has_pending_exceptions' => true,
        ],
    ], $overrides));
}

it('streams a whole-report pdf for an authorized role', function (string $role) {
    loginAsForReportPdf($role);
    $report = wholeReport();

    $response = $this->get(route('monthly-reports.pdf', ['report' => $report]));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
})->with(['admin', 'officer', 'principal', 'hr']);

it('rejects an unauthenticated request', function () {
    $report = wholeReport();

    $this->get(route('monthly-reports.pdf', ['report' => $report]))
        ->assertRedirect();
});

it('404s when the report has not been generated yet', function () {
    loginAsForReportPdf('admin');
    $report = MonthlyReport::factory()->create(['snapshot_json' => null]);

    $this->get(route('monthly-reports.pdf', ['report' => $report]))
        ->assertNotFound();
});

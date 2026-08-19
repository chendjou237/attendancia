<?php

use App\Enums\ReportState;
use App\Models\MonthlyReport;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function loginAsForPdf(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function reportWithTeacher(array $rowOverrides = [], array $reportOverrides = []): MonthlyReport
{
    return MonthlyReport::factory()->create(array_merge([
        'state' => ReportState::Draft,
        'snapshot_json' => [
            'generated_at' => now()->toIso8601String(),
            'hours_basis' => 'Each taught period counts as 1.00h.',
            'teachers' => [array_merge([
                'teacher_id' => 1,
                'staff_no' => 'T-0001',
                'full_name' => 'Ngwa Fon Peter',
                'employment_type' => 'hourly',
                'present' => 10,
                'present_admin' => 0,
                'absent' => 2,
                'absent_justified' => 0,
                'pending' => 0,
                'total_periods' => 12,
                'hours_taught' => 10.0,
            ], $rowOverrides)],
            'totals' => [],
            'has_pending_exceptions' => false,
        ],
    ], $reportOverrides));
}

it('streams a pdf for an authorized role', function (string $role) {
    loginAsForPdf($role);
    $report = reportWithTeacher();

    $response = $this->get(route('monthly-reports.teacher-pdf', ['report' => $report, 'teacherId' => 1]));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
})->with(['admin', 'officer', 'principal', 'hr']);

it('rejects an unauthenticated request', function () {
    $report = reportWithTeacher();

    $this->get(route('monthly-reports.teacher-pdf', ['report' => $report, 'teacherId' => 1]))
        ->assertRedirect();
});

it('404s for a teacher not present in that month\'s report', function () {
    loginAsForPdf('admin');
    $report = reportWithTeacher();

    $this->get(route('monthly-reports.teacher-pdf', ['report' => $report, 'teacherId' => 999]))
        ->assertNotFound();
});

it('404s when the report has not been generated yet', function () {
    loginAsForPdf('admin');
    $report = MonthlyReport::factory()->create(['snapshot_json' => null]);

    $this->get(route('monthly-reports.teacher-pdf', ['report' => $report, 'teacherId' => 1]))
        ->assertNotFound();
});

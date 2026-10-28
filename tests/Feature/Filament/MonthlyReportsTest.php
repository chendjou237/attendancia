<?php

use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Filament\Resources\MonthlyReports\Pages\ListMonthlyReports;
use App\Filament\Resources\MonthlyReports\Pages\ViewMonthlyReport;
use App\Http\Controllers\MonthlyReportPdfController;
use App\Models\AuditLog;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function actingAsRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

function emptySnapshot(): array
{
    return [
        'generated_at' => now()->toIso8601String(),
        'hours_basis' => 'test',
        'teachers' => [],
        'totals' => ['present' => 0, 'present_admin' => 0, 'absent' => 0, 'absent_justified' => 0, 'pending' => 0, 'payable_hours' => 0, 'oversight_hours' => 0],
        'has_pending_exceptions' => false,
    ];
}

it('generates a draft report for the chosen month from the header action', function () {
    actingAsRole('admin');
    $teacher = Teacher::factory()->create();
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);
    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);

    Livewire::test(ListMonthlyReports::class)
        ->callAction('generate', data: ['month' => now()->startOfMonth()->toDateString()]);

    $report = MonthlyReport::first();
    expect($report)->not->toBeNull();
    expect($report->state)->toBe(ReportState::Draft);
    expect($report->snapshot_json['totals']['present'])->toBe(1);
});

it('walks a report through the full workflow to sent-to-hr, auditing each step', function () {
    $officer = actingAsRole('officer');
    $report = MonthlyReport::factory()->create([
        'month' => now()->startOfMonth(),
        'state' => ReportState::Draft,
        'snapshot_json' => ['generated_at' => now()->toIso8601String(), 'teachers' => [], 'totals' => ['present' => 0, 'present_admin' => 0, 'absent' => 0, 'absent_justified' => 0, 'pending' => 0, 'payable_hours' => 0, 'oversight_hours' => 0], 'has_pending_exceptions' => false, 'hours_basis' => 'x'],
    ]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->callAction('markReviewed');

    $report->refresh();
    expect($report->state)->toBe(ReportState::OfficerReviewed);
    expect(AuditLog::where('entity', 'monthly_reports')->where('action', 'officer_reviewed')->where('actor_id', $officer->id)->exists())->toBeTrue();

    $principal = actingAsRole('principal');

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->callAction('approve');

    $report->refresh();
    expect($report->state)->toBe(ReportState::PrincipalApproved);
    expect($report->approved_by)->toBe($principal->id);
    expect($report->approved_at)->not->toBeNull();

    actingAsRole('admin');

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->callAction('sendToHr');

    $report->refresh();
    expect($report->state)->toBe(ReportState::SentToHr);
    expect($report->sent_to_hr_at)->not->toBeNull();

    expect(AuditLog::where('entity', 'monthly_reports')->where('entity_id', $report->id)->count())->toBe(3);
});

it('does not offer the approve action to a non-principal, non-admin user', function () {
    actingAsRole('officer');
    $report = MonthlyReport::factory()->create(['state' => ReportState::OfficerReviewed, 'snapshot_json' => emptySnapshot()]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->assertActionHidden('approve');
});

it('does not offer regenerate once a report is principal-approved or later', function () {
    actingAsRole('admin');
    $report = MonthlyReport::factory()->create(['state' => ReportState::PrincipalApproved, 'snapshot_json' => emptySnapshot()]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->assertActionHidden('regenerate')
        ->assertActionHidden('markReviewed')
        ->assertActionHidden('approve')
        ->assertActionVisible('sendToHr');
});

it('offers the download-pdf action once a report has been generated, hides it otherwise', function () {
    actingAsRole('admin');
    $generated = MonthlyReport::factory()->create(['month' => now()->startOfMonth(), 'state' => ReportState::Draft, 'snapshot_json' => emptySnapshot()]);
    $ungenerated = MonthlyReport::factory()->create(['month' => now()->startOfMonth()->subMonthNoOverflow(), 'state' => ReportState::Draft, 'snapshot_json' => null]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $generated->getRouteKey()])
        ->assertActionVisible('downloadPdf');

    Livewire::test(ViewMonthlyReport::class, ['record' => $ungenerated->getRouteKey()])
        ->assertActionHidden('downloadPdf');
});

it('regenerating a draft report through the page refreshes its snapshot', function () {
    actingAsRole('admin');
    $teacher = Teacher::factory()->create();
    $rule = RuleVersion::factory()->create(['hours_per_period' => 1.00]);
    $report = MonthlyReport::factory()->create([
        'month' => now()->startOfMonth(),
        'state' => ReportState::Draft,
        'snapshot_json' => emptySnapshot(),
    ]);

    PeriodResult::factory()->create(['teacher_id' => $teacher->id, 'rule_version_id' => $rule->id, 'status' => PeriodStatus::Present, 'date' => now()->startOfMonth()->addDay()]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->callAction('regenerate');

    $report->refresh();
    expect($report->snapshot_json['totals']['present'])->toBe(1);
});

it('shows each teacher\'s total periods and a TOTAL row, even on a frozen report', function () {
    actingAsRole('admin');
    $row = fn (string $name, int $present, int $pending, int $total) => [
        'teacher_id' => 1, 'staff_no' => $name, 'full_name' => $name, 'employment_type' => 'hourly',
        'present' => $present, 'present_admin' => 0, 'absent' => 1, 'absent_justified' => 0,
        'pending' => $pending, 'total_periods' => $total, 'hours_taught' => $present,
    ];
    // Sent to HR: never regenerated, so the TOTAL must come from the stored rows.
    $report = MonthlyReport::factory()->create([
        'state' => ReportState::SentToHr,
        'snapshot_json' => [...emptySnapshot(), 'teachers' => [$row('AHANDA', 10, 3, 14), $row('IROI', 20, 5, 26)]],
    ]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->assertSee(__('panel.resources.monthly_reports.total_periods'))
        ->assertSeeInOrder(['AHANDA', '14', 'IROI', '26', __('panel.resources.monthly_reports.total_row'), '30', '0', '2', '0', '8', '40', '30']);
});

it('keeps only known print columns, in print order, and falls back to all', function () {
    $all = array_keys(MonthlyReportPdfController::columns());

    expect(MonthlyReportPdfController::selectedColumns('hours_taught,bogus,present'))->toBe(['present', 'hours_taught'])
        ->and(MonthlyReportPdfController::selectedColumns(''))->toBe($all)
        ->and(MonthlyReportPdfController::selectedColumns('bogus'))->toBe($all);
});

it('prints only the chosen columns, with the name and a matching TOTAL row', function () {
    $teachers = [[
        'teacher_id' => 1, 'staff_no' => 'T-1', 'full_name' => 'AHANDA', 'employment_type' => 'hourly',
        'present' => 7, 'present_admin' => 0, 'absent' => 3, 'absent_justified' => 0,
        'pending' => 2, 'total_periods' => 12, 'hours_taught' => 7,
    ]];
    $report = MonthlyReport::factory()->create(['snapshot_json' => [...emptySnapshot(), 'teachers' => $teachers]]);

    $html = view('pdf.monthly-report', [
        'report' => $report, 'snapshot' => $report->snapshot_json, 'columns' => ['present', 'total_periods'],
    ])->render();
    $start = strpos($html, 'class="teachers"');
    $table = substr($html, $start, strpos($html, '</table>', $start) - $start);

    expect($table)->toContain('AHANDA')
        ->toContain(PeriodStatus::Present->getLabel())
        ->toContain(__('panel.resources.monthly_reports.total_periods'))
        ->not->toContain(PeriodStatus::Absent->getLabel())
        ->not->toContain('T-1')
        ->toContain(__('panel.resources.monthly_reports.total_row'));
});

it('downloads the PDF with the chosen columns and remembers the choice', function () {
    actingAsRole('admin');
    $report = MonthlyReport::factory()->create(['month' => '2026-10-01', 'state' => ReportState::Draft, 'snapshot_json' => emptySnapshot()]);

    Livewire::test(ViewMonthlyReport::class, ['record' => $report->getRouteKey()])
        ->callAction('downloadPdf', ['columns' => ['present', 'hours_taught']])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('monthly-report-2026-10.pdf');

    expect(session('monthly_report_pdf_columns'))->toBe(['present', 'hours_taught']);
});

it('renders the teachers count as a single number, not one per teacher', function () {
    actingAsRole('admin');

    // snapshot_json.teachers is a list of per-teacher arrays. A column
    // pointed straight at that dot-path would have Filament treat the
    // array as a multi-value list and format each teacher's row
    // individually — three teachers each with 11 snapshot keys would
    // render as "11, 11, 11" instead of "3". This locks in the fix
    // (Tables/MonthlyReportsTable.php uses ->state() to bypass that).
    $teachers = collect(range(1, 3))->map(fn () => [
        'teacher_id' => 1, 'staff_no' => 'T', 'full_name' => 'T', 'employment_type' => 'hourly',
        'present' => 0, 'present_admin' => 0, 'absent' => 0, 'absent_justified' => 0,
        'pending' => 0, 'total_periods' => 0, 'hours_taught' => 0,
    ])->all();

    $report = MonthlyReport::factory()->create([
        'snapshot_json' => [...emptySnapshot(), 'teachers' => $teachers],
    ]);

    $html = Livewire::test(ListMonthlyReports::class)->html();

    expect($html)->toContain('>3<');
    expect($html)->not->toContain('11, 11, 11');
});

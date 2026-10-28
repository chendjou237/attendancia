<?php

namespace App\Http\Controllers;

use App\Enums\PeriodStatus;
use App\Models\MonthlyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The whole-school counterpart to TeacherMonthlyReportPdfController —
 * every teacher's row from the same snapshot, for handing to the
 * Principal or HR as a single document rather than one PDF per
 * teacher. Same source of truth, same caveats (draft/pending-exceptions
 * notices), same access as the Monthly Reports screen itself.
 *
 * ?columns=a,b,c picks which columns print (chosen in the download
 * modal on ViewMonthlyReport). The teacher's name always prints — a row
 * without it is unreadable.
 */
class MonthlyReportPdfController extends Controller
{
    /**
     * Printable columns, in print order, with their labels. Also the
     * allow-list for ?columns — anything else in the query is ignored.
     *
     * @return array<string, string>
     */
    public static function columns(): array
    {
        return [
            'staff_no' => __('panel.common.staff_no'),
            'type' => __('panel.resources.monthly_reports.type'),
            'present' => PeriodStatus::Present->getLabel(),
            'present_admin' => PeriodStatus::PresentAdmin->getLabel(),
            'absent' => PeriodStatus::Absent->getLabel(),
            'absent_justified' => PeriodStatus::AbsentJustified->getLabel(),
            'pending' => __('panel.resources.monthly_reports.pending'),
            'total_periods' => __('panel.resources.monthly_reports.total_periods'),
            'hours_taught' => __('panel.resources.monthly_reports.hours'),
        ];
    }

    /**
     * The requested columns, filtered to known ones and kept in print
     * order; none (or none valid) means all.
     *
     * @return list<string>
     */
    public static function selectedColumns(?string $requested): array
    {
        $all = array_keys(self::columns());
        $wanted = array_filter(array_map('trim', explode(',', (string) $requested)));
        $selected = array_values(array_intersect($all, $wanted));

        return $selected === [] ? $all : $selected;
    }

    public function __invoke(Request $request, MonthlyReport $report): Response
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'officer', 'principal', 'hr']), Response::HTTP_FORBIDDEN);

        abort_if($report->snapshot_json === null, 404, 'This report has not been generated yet.');

        return self::makePdf($report, self::selectedColumns($request->query('columns')))
            ->stream(self::filename($report));
    }

    /**
     * Shared with ViewMonthlyReport's download action, so the button and
     * this route can never print different documents.
     *
     * @param  list<string>  $columns  already passed through selectedColumns()
     */
    public static function makePdf(MonthlyReport $report, array $columns): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.monthly-report', [
            'report' => $report,
            'snapshot' => $report->snapshot_json,
            'columns' => $columns,
        ])->setPaper('a4', 'landscape');
    }

    public static function filename(MonthlyReport $report): string
    {
        return sprintf('monthly-report-%s.pdf', $report->month->format('Y-m'));
    }
}

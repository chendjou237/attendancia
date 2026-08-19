<?php

namespace App\Http\Controllers;

use App\Models\MonthlyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * The whole-school counterpart to TeacherMonthlyReportPdfController —
 * every teacher's row from the same snapshot, for handing to the
 * Principal or HR as a single document rather than one PDF per
 * teacher. Same source of truth, same caveats (draft/pending-exceptions
 * notices), same access as the Monthly Reports screen itself.
 */
class MonthlyReportPdfController extends Controller
{
    public function __invoke(MonthlyReport $report): Response
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'officer', 'principal', 'hr']), Response::HTTP_FORBIDDEN);

        $snapshot = $report->snapshot_json;
        abort_if($snapshot === null, 404, 'This report has not been generated yet.');

        $pdf = Pdf::loadView('pdf.monthly-report', [
            'report' => $report,
            'snapshot' => $snapshot,
        ])->setPaper('a4', 'landscape');

        $filename = sprintf('monthly-report-%s.pdf', $report->month->format('Y-m'));

        return $pdf->stream($filename);
    }
}

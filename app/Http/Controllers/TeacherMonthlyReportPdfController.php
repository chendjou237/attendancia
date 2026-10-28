<?php

namespace App\Http\Controllers;

use App\Models\MonthlyReport;
use App\Models\Teacher;
use App\Services\Reporting\TeacherMonthDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * §10: a teacher's dispute or payslip question shouldn't have to wait
 * on a screen only staff can see — but building teacher logins was an
 * explicitly open, undecided question in the project scope. This is
 * the staff-triggered middle ground: whoever can already see the
 * Monthly Reports screen (admin/officer/principal/hr) hands a teacher
 * a PDF of their own slice of it, generated from the same snapshot
 * everyone else is looking at rather than a fresh computation — a
 * Draft report's PDF and the numbers on screen can never disagree.
 */
class TeacherMonthlyReportPdfController extends Controller
{
    public function __invoke(MonthlyReport $report, int $teacherId, TeacherMonthDetail $monthDetail): Response
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'officer', 'principal', 'hr']), Response::HTTP_FORBIDDEN);

        $snapshot = $report->snapshot_json;
        abort_if($snapshot === null, 404, 'This report has not been generated yet.');

        $row = collect($snapshot['teachers'])->firstWhere('teacher_id', $teacherId);
        abort_if($row === null, 404, 'No record for this teacher in this month\'s report.');

        // The day-by-day evidence under the summary line. Null only if the
        // teacher row itself is gone, in which case the summary still prints.
        $teacher = Teacher::find($teacherId);
        $detail = $teacher !== null ? $monthDetail->build($teacher, $report->month) : null;

        $pdf = Pdf::loadView('pdf.teacher-monthly-report', [
            'report' => $report,
            'row' => $row,
            'snapshot' => $snapshot,
            'detail' => $detail,
            'detailMatchesSummary' => $detail === null || TeacherMonthDetail::matchesSummary($detail['counts'], $row),
        ])->setPaper('a4');

        $filename = sprintf('%s-%s.pdf', $row['staff_no'], $report->month->format('Y-m'));

        return $pdf->stream($filename);
    }
}

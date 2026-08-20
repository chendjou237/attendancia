<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $row['staff_no'] }} — {{ $report->month->translatedFormat('F Y') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .subtitle { color: #555; margin-top: 4px; margin-bottom: 24px; }
        .draft-notice {
            background: #fff3cd; border: 1px solid #d4a72c; color: #664d03;
            padding: 10px 14px; margin-bottom: 20px; font-size: 11px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-size: 11px; text-transform: uppercase; color: #555; }
        .totals td { font-weight: bold; }
        .meta { font-size: 11px; color: #555; margin-top: 30px; }
        .footer { font-size: 10px; color: #888; margin-top: 40px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ __('panel.resources.monthly_reports.pdf_title') }}</h1>
    <div class="subtitle">{{ $row['full_name'] }} ({{ $row['staff_no'] }}) — {{ $report->month->translatedFormat('F Y') }}</div>

    @include('pdf.partials.draft-notice', [
        'report' => $report,
        'pendingMessage' => ($row['pending'] ?? 0) > 0
            ? __('panel.resources.monthly_reports.pending_teacher', ['count' => $row['pending']])
            : null,
    ])

    <table>
        <thead>
            <tr>
                <th>{{ __('panel.common.employment_type') }}</th>
                <th>{{ \App\Enums\PeriodStatus::Present->getLabel() }}</th>
                <th>{{ \App\Enums\PeriodStatus::PresentAdmin->getLabel() }}</th>
                <th>{{ \App\Enums\PeriodStatus::Absent->getLabel() }}</th>
                <th>{{ \App\Enums\PeriodStatus::AbsentJustified->getLabel() }}</th>
                <th>{{ __('panel.resources.monthly_reports.pending') }}</th>
                <th>{{ __('panel.resources.monthly_reports.total_periods') }}</th>
                <th>{{ __('panel.resources.monthly_reports.hours') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="totals">
                <td>{{ \App\Enums\EmploymentType::from($row['employment_type'])->getLabel() }}</td>
                <td>{{ $row['present'] }}</td>
                <td>{{ $row['present_admin'] }}</td>
                <td>{{ $row['absent'] }}</td>
                <td>{{ $row['absent_justified'] }}</td>
                <td>{{ $row['pending'] }}</td>
                <td>{{ $row['total_periods'] }}</td>
                <td>{{ $row['hours_taught'] }}</td>
            </tr>
        </tbody>
    </table>

    @include('pdf.partials.footer', ['report' => $report, 'snapshot' => $snapshot])
</body>
</html>

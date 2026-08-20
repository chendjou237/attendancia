<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('panel.resources.monthly_reports.pdf_subtitle_all', ['month' => $report->month->translatedFormat('F Y')]) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .subtitle { color: #555; margin-top: 4px; margin-bottom: 20px; }
        .draft-notice {
            background: #fff3cd; border: 1px solid #d4a72c; color: #664d03;
            padding: 10px 14px; margin-bottom: 16px; font-size: 11px;
        }
        .summary { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .summary td { padding: 8px 14px; border: 1px solid #ddd; }
        .summary .label { font-size: 9px; text-transform: uppercase; color: #777; display: block; margin-bottom: 2px; }
        .summary .value { font-size: 16px; font-weight: bold; }
        table.teachers { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.teachers th, table.teachers td { text-align: left; padding: 5px 8px; border-bottom: 1px solid #ddd; }
        table.teachers th { background: #f5f5f5; font-size: 10px; text-transform: uppercase; color: #555; }
        table.teachers td.num, table.teachers th.num { text-align: right; }
        .pending-flag { color: #b91c1c; }
        .meta { font-size: 11px; color: #555; margin-top: 20px; }
        .footer { font-size: 10px; color: #888; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ __('panel.resources.monthly_reports.pdf_title') }}</h1>
    <div class="subtitle">{{ __('panel.resources.monthly_reports.pdf_subtitle_all', ['month' => $report->month->translatedFormat('F Y')]) }}</div>

    @include('pdf.partials.draft-notice', [
        'report' => $report,
        'pendingMessage' => $snapshot['has_pending_exceptions']
            ? __('panel.resources.monthly_reports.pending_school', ['count' => $snapshot['totals']['pending']])
            : null,
    ])

    <table class="summary">
        <tr>
            <td><span class="label">{{ \App\Enums\EmploymentType::Hourly->getLabel() }} — {{ __('panel.resources.monthly_reports.hours') }}</span><span class="value">{{ $snapshot['totals']['payable_hours'] }}</span></td>
            <td><span class="label">{{ \App\Enums\EmploymentType::Salaried->getLabel() }} — {{ __('panel.resources.monthly_reports.hours') }}</span><span class="value">{{ $snapshot['totals']['oversight_hours'] }}</span></td>
            <td><span class="label">{{ \App\Enums\PeriodStatus::Present->getLabel() }}</span><span class="value">{{ $snapshot['totals']['present'] + $snapshot['totals']['present_admin'] }}</span></td>
            <td><span class="label">{{ \App\Enums\PeriodStatus::Absent->getLabel() }}</span><span class="value">{{ $snapshot['totals']['absent'] + $snapshot['totals']['absent_justified'] }}</span></td>
            <td><span class="label">{{ __('panel.resources.monthly_reports.pending') }}</span><span class="value">{{ $snapshot['totals']['pending'] }}</span></td>
        </tr>
    </table>

    <table class="teachers">
        <thead>
            <tr>
                <th>{{ __('panel.common.staff_no') }}</th>
                <th>{{ __('panel.common.teacher') }}</th>
                <th>{{ __('panel.resources.monthly_reports.type') }}</th>
                <th class="num">{{ \App\Enums\PeriodStatus::Present->getLabel() }}</th>
                <th class="num">{{ \App\Enums\PeriodStatus::PresentAdmin->getLabel() }}</th>
                <th class="num">{{ \App\Enums\PeriodStatus::Absent->getLabel() }}</th>
                <th class="num">{{ \App\Enums\PeriodStatus::AbsentJustified->getLabel() }}</th>
                <th class="num">{{ __('panel.resources.monthly_reports.pending') }}</th>
                <th class="num">{{ __('panel.resources.monthly_reports.total_periods') }}</th>
                <th class="num">{{ __('panel.resources.monthly_reports.hours') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['teachers'] as $row)
                <tr>
                    <td>{{ $row['staff_no'] }}</td>
                    <td>{{ $row['full_name'] }}</td>
                    <td>{{ \App\Enums\EmploymentType::from($row['employment_type'])->getLabel() }}</td>
                    <td class="num">{{ $row['present'] }}</td>
                    <td class="num">{{ $row['present_admin'] }}</td>
                    <td class="num">{{ $row['absent'] }}</td>
                    <td class="num">{{ $row['absent_justified'] }}</td>
                    <td class="num {{ $row['pending'] > 0 ? 'pending-flag' : '' }}">{{ $row['pending'] }}</td>
                    <td class="num">{{ $row['total_periods'] }}</td>
                    <td class="num" style="font-weight: bold">{{ $row['hours_taught'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align: center; color: #888; padding: 12px">{{ __('panel.resources.monthly_reports.no_period_results') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('pdf.partials.footer', ['report' => $report, 'snapshot' => $snapshot])
</body>
</html>

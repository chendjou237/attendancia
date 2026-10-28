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
        h2 { font-size: 14px; margin: 24px 0 8px; }
        .notice { font-size: 10px; color: #664d03; background: #fff3cd; border: 1px solid #d4a72c; padding: 6px 10px; margin-bottom: 12px; }
        .day { page-break-inside: avoid; margin-bottom: 14px; }
        .day-head { font-weight: bold; font-size: 12px; border-bottom: 2px solid #333; padding: 4px 0; }
        .day table { margin-bottom: 4px; font-size: 10px; }
        .day th, .day td { padding: 3px 6px; }
        .day .num { text-align: right; }
        .why { color: #777; font-size: 9px; }
        .scans, .day-totals { font-size: 10px; color: #333; margin: 2px 0; }
        .s-present { color: #166534; } .s-absent { color: #b91c1c; } .s-pending { color: #b45309; }
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

    @if (($detail ?? null) !== null && count($detail['days']) > 0)
        <h2>{{ __('panel.resources.monthly_reports.day_detail') }}</h2>

        @unless ($detailMatchesSummary ?? true)
            <div class="notice">{{ __('panel.resources.monthly_reports.detail_differs', ['date' => $report->generated_at?->setTimezone(config('attendance.timezone'))->format('d/m/Y H:i')]) }}</div>
        @endunless

        @foreach ($detail['days'] as $day)
            <div class="day">
                <div class="day-head">{{ ucfirst($day['date']->translatedFormat('l d/m/Y')) }}</div>

                @if (count($day['periods']) > 0)
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('panel.common.period') }}</th>
                                <th>{{ __('panel.common.start') }}–{{ __('panel.common.end') }}</th>
                                <th>{{ __('panel.common.class') }}</th>
                                <th>{{ __('panel.common.status') }}</th>
                                <th class="num">{{ __('panel.resources.monthly_reports.hours') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($day['periods'] as $period)
                                @php($tone = $period['status']->isPending() ? 's-pending' : ($period['status']->countsAsTaught() ? 's-present' : 's-absent'))
                                <tr>
                                    <td>{{ $period['seq'] }}</td>
                                    <td>{{ $period['time'] }}</td>
                                    <td>{{ $period['class'] }}</td>
                                    <td class="{{ $tone }}">
                                        {{ $period['status']->getLabel() }}
                                        @if ($period['why'])
                                            <span class="why">— {{ $period['why'] }}</span>
                                        @endif
                                        @if ($period['override_reason'])
                                            <span class="why">— {{ __('panel.resources.monthly_reports.corrected', ['reason' => $period['override_reason']]) }}</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ $period['hours_present'] > 0 ? $period['hours_present'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="font-weight: bold; border-top: 1px solid #333">
                                <td colspan="3">{{ __('panel.resources.monthly_reports.day_present_total') }}</td>
                                <td class="s-present">{{ trans_choice('panel.resources.monthly_reports.present_periods', $day['present_periods'], ['count' => $day['present_periods']]) }}</td>
                                <td class="num">{{ $day['hours_present'] }} h</td>
                            </tr>
                        </tfoot>
                    </table>
                @endif

                <div class="scans">
                    <strong>{{ __('panel.resources.monthly_reports.scans') }} :</strong>
                    @forelse ($day['scans'] as $scan)
                        {{ $scan['time'] }} ({{ $scan['place'] }}){{ $loop->last ? '' : ', ' }}
                    @empty
                        {{ __('panel.resources.monthly_reports.no_scans') }}
                    @endforelse
                </div>

                @if (count($day['periods']) > 0)
                    <div class="day-totals">
                        {{ __('panel.resources.monthly_reports.day_totals', [
                            'present' => $day['hours_present'],
                            'absent' => $day['hours_absent'],
                            'pending' => $day['pending'],
                        ]) }}
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    @include('pdf.partials.footer', ['report' => $report, 'snapshot' => $snapshot])
</body>
</html>

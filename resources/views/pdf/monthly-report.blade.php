<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Monthly report — {{ $report->month->format('F Y') }}</title>
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
    <h1>Monthly Attendance Report</h1>
    <div class="subtitle">{{ $report->month->format('F Y') }} — all teachers</div>

    @if ($report->state->value !== 'principal_approved' && $report->state->value !== 'sent_to_hr')
        <div class="draft-notice">
            This report is still <strong>{{ $report->state->getLabel() }}</strong> — it has not been approved
            and these numbers may still change before the month is closed out.
        </div>
    @endif

    @if ($snapshot['has_pending_exceptions'])
        <div class="draft-notice">
            {{ $snapshot['totals']['pending'] }} period(s) across the school this month are still unresolved
            (unpaired or location-mismatch) and are not counted as present or absent below until the
            Exception Queue resolves them — the affected teachers' totals are undercounted, not wrong.
        </div>
    @endif

    <table class="summary">
        <tr>
            <td><span class="label">Payable hours (hourly staff)</span><span class="value">{{ $snapshot['totals']['payable_hours'] }}</span></td>
            <td><span class="label">Oversight hours (salaried staff)</span><span class="value">{{ $snapshot['totals']['oversight_hours'] }}</span></td>
            <td><span class="label">Present</span><span class="value">{{ $snapshot['totals']['present'] + $snapshot['totals']['present_admin'] }}</span></td>
            <td><span class="label">Absent</span><span class="value">{{ $snapshot['totals']['absent'] + $snapshot['totals']['absent_justified'] }}</span></td>
            <td><span class="label">Pending</span><span class="value">{{ $snapshot['totals']['pending'] }}</span></td>
        </tr>
    </table>

    <table class="teachers">
        <thead>
            <tr>
                <th>Staff No</th>
                <th>Teacher</th>
                <th>Type</th>
                <th class="num">Present</th>
                <th class="num">Present (admin)</th>
                <th class="num">Absent</th>
                <th class="num">Absent (justified)</th>
                <th class="num">Pending</th>
                <th class="num">Total periods</th>
                <th class="num">Hours</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['teachers'] as $row)
                <tr>
                    <td>{{ $row['staff_no'] }}</td>
                    <td>{{ $row['full_name'] }}</td>
                    <td style="text-transform: capitalize">{{ $row['employment_type'] }}</td>
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
                    <td colspan="10" style="text-align: center; color: #888; padding: 12px">No period results for this month.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="meta">
        Report state: {{ $report->state->getLabel() }}
        @if ($report->approved_by) &middot; Approved by {{ $report->approvedBy->name }} @endif
        @if ($report->sent_to_hr_at) &middot; Sent to HR {{ $report->sent_to_hr_at->format('d M Y') }} @endif
    </div>

    <div class="footer">
        {{ $snapshot['hours_basis'] }}
        <br>
        Generated {{ now()->format('d M Y, H:i') }} for internal use — not a substitute for the official
        monthly report record.
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $row['staff_no'] }} — {{ $report->month->format('F Y') }}</title>
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
    <h1>Monthly Attendance Report</h1>
    <div class="subtitle">{{ $row['full_name'] }} ({{ $row['staff_no'] }}) — {{ $report->month->format('F Y') }}</div>

    @if ($report->state->value !== 'principal_approved' && $report->state->value !== 'sent_to_hr')
        <div class="draft-notice">
            This report is still <strong>{{ $report->state->getLabel() }}</strong> — it has not been approved
            and these numbers may still change before the month is closed out.
        </div>
    @endif

    @if (($row['pending'] ?? 0) > 0)
        <div class="draft-notice">
            {{ $row['pending'] }} period(s) for this teacher this month are still unresolved (unpaired or
            location-mismatch) and are not counted as present or absent below until the Exception Queue
            resolves them.
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Employment type</th>
                <th>Present</th>
                <th>Present (admin)</th>
                <th>Absent</th>
                <th>Absent (justified)</th>
                <th>Pending</th>
                <th>Total periods</th>
                <th>Hours</th>
            </tr>
        </thead>
        <tbody>
            <tr class="totals">
                <td style="text-transform: capitalize">{{ $row['employment_type'] }}</td>
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

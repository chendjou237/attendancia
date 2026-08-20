{{-- Shared between both PDF views — see draft-notice.blade.php's note. --}}
<div class="meta">
    {{ __('panel.resources.monthly_reports.report_state_prefix') }} {{ $report->state->getLabel() }}
    @if ($report->approved_by) &middot; {{ __('panel.common.approved_by') }} {{ $report->approvedBy->name }} @endif
    @if ($report->sent_to_hr_at) &middot; {{ __('panel.resources.monthly_reports.sent_to_hr') }} {{ $report->sent_to_hr_at->translatedFormat('d M Y') }} @endif
</div>

<div class="footer">
    {{ $snapshot['hours_basis'] }}
    <br>
    {{ __('panel.resources.monthly_reports.footer_disclaimer', ['date' => now()->translatedFormat('d M Y, H:i')]) }}
</div>

{{--
    Shared between monthly-report.blade.php and teacher-monthly-report.blade.php
    — both previously had this block byte-identical, translated (and
    liable to drift) twice. $pendingMessage is built by the caller since
    the whole-school vs per-teacher wording genuinely differs (a real
    difference to keep, not duplication to remove).
--}}
@if ($report->state->value !== 'principal_approved' && $report->state->value !== 'sent_to_hr')
    <div class="draft-notice">
        {{-- {!! !!}: the translation embeds a <strong> tag around the
             state label — trusted, server-controlled content (an enum
             label), not user input, so unescaped output is safe here. --}}
        {!! __('panel.resources.monthly_reports.draft_notice', ['state' => '<strong>'.e($report->state->getLabel()).'</strong>']) !!}
    </div>
@endif

@if ($pendingMessage ?? null)
    <div class="draft-notice">
        {{ $pendingMessage }}
    </div>
@endif

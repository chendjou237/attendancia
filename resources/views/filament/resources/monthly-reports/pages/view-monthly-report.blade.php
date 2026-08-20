<x-filament-panels::page>
    @php
        $snapshot = $this->record->snapshot_json;
        $stateColors = [
            'draft' => 'gray',
            'officer_reviewed' => 'info',
            'principal_approved' => 'warning',
            'sent_to_hr' => 'success',
        ];
    @endphp

    {{--
        Inline style throughout, not Tailwind utility classes — this
        page has no Tailwind build of its own, and Filament's app.css is
        a purged bundle containing only the classes its own components
        use (see the identical note in manage-timetable.blade.php).
    --}}

    @if (! $snapshot)
        <x-filament::callout
            color="gray"
            icon="heroicon-o-document"
            :heading="__('panel.resources.monthly_reports.not_generated_heading')"
            :description="__('panel.resources.monthly_reports.not_generated_description')"
        />
    @else
        <div style="display:flex;flex-direction:column;gap:1.5rem">
            <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center">
                <x-filament::badge :color="$stateColors[$this->record->state->value]" size="lg">
                    {{ $this->record->state->getLabel() }}
                </x-filament::badge>
                <span style="font-size:0.875rem;color:rgb(156 163 175)">
                    {{ __('panel.resources.monthly_reports.generated_prefix') }} {{ \Illuminate\Support\Carbon::parse($snapshot['generated_at'])->diffForHumans() }}
                </span>
                @if ($this->record->approved_by)
                    <span style="font-size:0.875rem;color:rgb(156 163 175)">
                        · {{ __('panel.common.approved_by') }} {{ $this->record->approvedBy->name }}
                    </span>
                @endif
                @if ($this->record->sent_to_hr_at)
                    <span style="font-size:0.875rem;color:rgb(156 163 175)">
                        · {{ __('panel.resources.monthly_reports.sent_to_hr') }} {{ $this->record->sent_to_hr_at->diffForHumans() }}
                    </span>
                @endif
            </div>

            @if ($snapshot['has_pending_exceptions'])
                <x-filament::callout
                    color="danger"
                    icon="heroicon-o-exclamation-triangle"
                    heading="{{ __('panel.resources.monthly_reports.pending_heading', ['count' => $snapshot['totals']['pending']]) }}"
                    :description="__('panel.resources.monthly_reports.pending_description')"
                />
            @endif

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(10rem, 1fr));gap:1rem">
                <x-filament::card>
                    <div style="font-size:0.75rem;color:rgb(156 163 175)">{{ \App\Enums\EmploymentType::Hourly->getLabel() }} — {{ __('panel.resources.monthly_reports.hours') }}</div>
                    <div style="font-size:1.5rem;font-weight:600">{{ $snapshot['totals']['payable_hours'] }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div style="font-size:0.75rem;color:rgb(156 163 175)">{{ \App\Enums\EmploymentType::Salaried->getLabel() }} — {{ __('panel.resources.monthly_reports.hours') }}</div>
                    <div style="font-size:1.5rem;font-weight:600">{{ $snapshot['totals']['oversight_hours'] }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div style="font-size:0.75rem;color:rgb(156 163 175)">{{ \App\Enums\PeriodStatus::Present->getLabel() }}</div>
                    <div style="font-size:1.5rem;font-weight:600">{{ $snapshot['totals']['present'] + $snapshot['totals']['present_admin'] }}</div>
                </x-filament::card>
                <x-filament::card>
                    <div style="font-size:0.75rem;color:rgb(156 163 175)">{{ \App\Enums\PeriodStatus::Absent->getLabel() }}</div>
                    <div style="font-size:1.5rem;font-weight:600">{{ $snapshot['totals']['absent'] + $snapshot['totals']['absent_justified'] }}</div>
                </x-filament::card>
            </div>

            <x-filament::card>
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                        <thead>
                            <tr style="text-align:left;border-bottom:1px solid rgb(63 63 70)">
                                <th style="padding:0.5rem">{{ __('panel.common.staff_no') }}</th>
                                <th style="padding:0.5rem">{{ __('panel.common.teacher') }}</th>
                                <th style="padding:0.5rem">{{ __('panel.resources.monthly_reports.type') }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ \App\Enums\PeriodStatus::Present->getLabel() }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ \App\Enums\PeriodStatus::PresentAdmin->getLabel() }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ \App\Enums\PeriodStatus::Absent->getLabel() }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ \App\Enums\PeriodStatus::AbsentJustified->getLabel() }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ __('panel.resources.monthly_reports.pending') }}</th>
                                <th style="padding:0.5rem;text-align:right">{{ __('panel.resources.monthly_reports.hours') }}</th>
                                <th style="padding:0.5rem"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($snapshot['teachers'] as $row)
                                <tr style="border-bottom:1px solid rgb(39 39 42)">
                                    <td style="padding:0.5rem">{{ $row['staff_no'] }}</td>
                                    <td style="padding:0.5rem">{{ $row['full_name'] }}</td>
                                    <td style="padding:0.5rem">{{ \App\Enums\EmploymentType::from($row['employment_type'])->getLabel() }}</td>
                                    <td style="padding:0.5rem;text-align:right">{{ $row['present'] }}</td>
                                    <td style="padding:0.5rem;text-align:right">{{ $row['present_admin'] }}</td>
                                    <td style="padding:0.5rem;text-align:right">{{ $row['absent'] }}</td>
                                    <td style="padding:0.5rem;text-align:right">{{ $row['absent_justified'] }}</td>
                                    <td style="padding:0.5rem;text-align:right;{{ $row['pending'] > 0 ? 'color:rgb(248 113 113)' : '' }}">{{ $row['pending'] }}</td>
                                    <td style="padding:0.5rem;text-align:right;font-weight:600">{{ $row['hours_taught'] }}</td>
                                    <td style="padding:0.5rem;text-align:right">
                                        <a
                                            href="{{ route('monthly-reports.teacher-pdf', ['report' => $this->record, 'teacherId' => $row['teacher_id']]) }}"
                                            target="_blank"
                                            style="color:rgb(251 146 60);text-decoration:none;font-size:0.8125rem;white-space:nowrap"
                                        >
                                            {{ __('panel.common.download_pdf') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" style="padding:1rem;text-align:center;color:rgb(156 163 175)">
                                        {{ __('panel.resources.monthly_reports.no_period_results') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::card>

            <p style="font-size:0.75rem;color:rgb(156 163 175)">
                {{ $snapshot['hours_basis'] }}
            </p>
        </div>
    @endif
</x-filament-panels::page>

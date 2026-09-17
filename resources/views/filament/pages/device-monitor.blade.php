<x-filament-panels::page>
    {{--
        Inline style throughout — this page has no Tailwind build of its
        own and Filament's app.css only ships the classes its own
        components use (see the same note in manage-timetable.blade.php
        and view-monthly-report.blade.php). The one exception is the
        <style> block below: real CSS, not utility classes, for the
        pulse/slide-in animations.
    --}}
    <style>
        @keyframes device-monitor-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
            70%  { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }
        .device-monitor-dot--online {
            animation: device-monitor-pulse 2s infinite;
        }
        .device-monitor-row-enter {
            animation: device-monitor-row-in 0.5s ease-out;
        }
        @keyframes device-monitor-row-in {
            from { opacity: 0; transform: translateY(-6px); background-color: rgba(251, 146, 60, 0.12); }
            to   { opacity: 1; transform: translateY(0); background-color: transparent; }
        }
    </style>

    <div wire:poll.3s="$refresh" style="display:flex;flex-direction:column;gap:1.5rem">

        {{-- Device health --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(15rem, 1fr));gap:1rem">
            @forelse ($this->devices as $device)
                @php
                    $colors = [
                        'online' => ['dot' => '#22c55e', 'text' => '#22c55e', 'label' => __('panel.pages.device_monitor.online')],
                        'warning' => ['dot' => '#f59e0b', 'text' => '#f59e0b', 'label' => __('panel.pages.device_monitor.silent')],
                        'offline' => ['dot' => '#ef4444', 'text' => '#ef4444', 'label' => __('panel.pages.device_monitor.offline')],
                    ][$device['status']];
                @endphp
                <x-filament::card>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem">
                        <div>
                            <div style="font-weight:600">{{ $device['serial'] }}</div>
                            <div style="font-size:0.8125rem;color:rgb(156 163 175)">{{ $device['corridor'] }}</div>
                            @if ($device['model'])
                                <div style="font-size:0.75rem;color:rgb(113 113 122);margin-top:2px">{{ $device['model'] }}</div>
                            @endif
                            @if ($device['ip'])
                                <div style="font-size:0.75rem;color:rgb(113 113 122);margin-top:2px">{{ $device['ip'] }}</div>
                            @endif
                        </div>
                        <div style="display:flex;align-items:center;gap:0.4rem;flex-shrink:0">
                            <span
                                class="{{ $device['status'] === 'online' ? 'device-monitor-dot--online' : '' }}"
                                style="width:0.6rem;height:0.6rem;border-radius:9999px;background-color:{{ $colors['dot'] }};display:inline-block"
                            ></span>
                            <span style="font-size:0.8125rem;font-weight:600;color:{{ $colors['text'] }}">{{ $colors['label'] }}</span>
                        </div>
                    </div>

                    <div
                        style="margin-top:0.75rem;font-size:0.75rem;color:rgb(156 163 175)"
                        @if ($device['last_seen_at'])
                            {{--
                                Translated server-side (Blade, where
                                app()->getLocale() is already correct)
                                and handed to Alpine as static JSON — the
                                client-side tick() function never needs
                                to know about locales, it just
                                concatenates whatever suffix string it
                                was given and drops it into the
                                __TIME__ slot of the already-translated
                                "last seen" template.
                            --}}
                            x-data="{
                                since: @js($device['last_seen_at']->toIso8601String()),
                                labels: @js([
                                    's' => __('panel.pages.device_monitor.ago_seconds'),
                                    'm' => __('panel.pages.device_monitor.ago_minutes'),
                                    'h' => __('panel.pages.device_monitor.ago_hours'),
                                    'd' => __('panel.pages.device_monitor.ago_days'),
                                    'template' => __('panel.pages.device_monitor.last_seen'),
                                ]),
                                text: '',
                                tick() {
                                    const seconds = Math.max(0, Math.floor((Date.now() - new Date(this.since).getTime()) / 1000));
                                    if (seconds < 60) { this.text = seconds + this.labels.s; }
                                    else if (seconds < 3600) { this.text = Math.floor(seconds / 60) + this.labels.m; }
                                    else if (seconds < 86400) { this.text = Math.floor(seconds / 3600) + this.labels.h; }
                                    else { this.text = Math.floor(seconds / 86400) + this.labels.d; }
                                },
                            }"
                            x-init="tick(); setInterval(() => tick(), 1000)"
                            x-text="labels.template.replace('__TIME__', text)"
                        @endif
                    >
                        @if (! $device['last_seen_at'])
                            {{ __('panel.pages.device_monitor.never_seen') }}
                        @endif
                    </div>
                </x-filament::card>
            @empty
                <x-filament::card>
                    <span style="color:rgb(156 163 175)">{{ __('panel.pages.device_monitor.no_devices') }}</span>
                </x-filament::card>
            @endforelse
        </div>

        {{-- Simulate scan --}}
        <x-filament::card>
            <div style="font-weight:600;margin-bottom:0.75rem">{{ __('panel.pages.device_monitor.simulate_scan_heading') }}</div>
            <p style="font-size:0.8125rem;color:rgb(156 163 175);margin-bottom:0.75rem">
                {{ __('panel.pages.device_monitor.simulate_scan_body') }}
            </p>

            @if ($this->enrolledTeachers->isEmpty())
                <div style="font-size:0.8125rem;color:rgb(156 163 175)">
                    {{ __('panel.pages.device_monitor.no_enrolled_teachers') }}
                </div>
            @else
                <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:0.75rem">
                    <div style="flex:1;min-width:12rem">
                        <label style="display:block;font-size:0.8125rem;font-weight:500;margin-bottom:0.25rem">{{ __('panel.common.device') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="selectedDeviceId">
                                @foreach ($this->devices as $device)
                                    <option value="{{ $device['id'] }}">{{ $device['serial'] }} — {{ $device['corridor'] }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div style="flex:1;min-width:12rem">
                        <label style="display:block;font-size:0.8125rem;font-weight:500;margin-bottom:0.25rem">{{ __('panel.common.teacher') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="selectedEnrolmentId">
                                <option value="">{{ __('panel.pages.device_monitor.select_teacher_placeholder') }}</option>
                                @foreach ($this->enrolledTeachers as $enrolment)
                                    <option value="{{ $enrolment->id }}">{{ $enrolment->teacher->full_name }} ({{ $enrolment->teacher->staff_no }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button wire:click="simulateScan" icon="heroicon-o-finger-print">
                        {{ __('panel.pages.device_monitor.simulate_scan_button') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::card>

        {{-- Live activity feed --}}
        <x-filament::card>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem">
                <div style="font-weight:600">{{ __('panel.pages.device_monitor.live_activity') }}</div>

                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                    <label style="display:flex;align-items:center;gap:0.375rem;font-size:0.75rem;color:rgb(113 113 122)">
                        <input type="checkbox" wire:model.live="onlyIdentified" />
                        {{ __('panel.pages.device_monitor.only_identified') }}
                    </label>

                    <label style="display:flex;align-items:center;gap:0.375rem;font-size:0.75rem;color:rgb(113 113 122)">
                        {{ __('panel.pages.device_monitor.rows_shown') }}
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="feedLimit">
                                @foreach (\App\Filament\Pages\DeviceMonitor::FEED_LIMITS as $size)
                                    <option value="{{ $size }}">{{ $size }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    <div style="font-size:0.75rem;color:rgb(113 113 122)">{{ __('panel.pages.device_monitor.updates_every_3s') }}</div>
                </div>
            </div>

            {{-- Capped height with its own scroll: 500 rows must not push
                 the device cards off the top of the page every poll. --}}
            <div style="overflow-x:auto;overflow-y:auto;max-height:36rem">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid rgb(63 63 70)">
                            <th style="padding:0.5rem">{{ __('panel.pages.device_monitor.time') }}</th>
                            <th style="padding:0.5rem">{{ __('panel.common.device') }}</th>
                            <th style="padding:0.5rem">{{ __('panel.common.teacher') }}</th>
                            <th style="padding:0.5rem">{{ __('panel.pages.device_monitor.result') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentEvents as $event)
                            <tr wire:key="raw-event-{{ $event->id }}" class="device-monitor-row-enter" style="border-bottom:1px solid rgb(39 39 42)">
                                {{-- School wall clock, not UTC: raw_events are stored in UTC
                                     (config/app.php), so formatting the Carbon as-is showed staff
                                     a time an hour off the clock on the wall — which reads as
                                     device clock drift and sends every "he scanned at 14:49"
                                     conversation down the wrong path. --}}
                                @php($localTime = $event->effectiveTime()->setTimezone(config('attendance.timezone')))
                                <td style="padding:0.5rem;white-space:nowrap">
                                    {{ $localTime->format('H:i:s') }}
                                    {{-- The date only when it isn't today: a longer feed reaches
                                         back past midnight, and a bare clock time there reads as
                                         a scan that just happened. --}}
                                    @unless ($localTime->isToday())
                                        <span style="color:rgb(156 163 175)">{{ $localTime->format('d/m') }}</span>
                                    @endunless
                                </td>
                                <td style="padding:0.5rem">{{ $event->device?->serial ?? $event->device_serial }}</td>
                                <td style="padding:0.5rem">
                                    @if ($event->teacher)
                                        {{ $event->teacher->full_name }}
                                    @else
                                        <span style="color:rgb(156 163 175)">{{ __('panel.pages.device_monitor.unmatched') }} ({{ $event->biometric_id ?? '—' }})</span>
                                    @endif
                                </td>
                                <td style="padding:0.5rem">
                                    @if ($event->sub_event_type === 38)
                                        <x-filament::badge color="success">{{ __('panel.pages.device_monitor.passed') }}</x-filament::badge>
                                    @elseif ($event->sub_event_type === 49)
                                        <x-filament::badge color="danger">{{ __('panel.pages.device_monitor.failed') }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">{{ __('panel.pages.device_monitor.other') }}</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:1rem;text-align:center;color:rgb(156 163 175)">
                                    {{ $onlyIdentified
                                        ? __('panel.pages.device_monitor.no_identified_scans')
                                        : __('panel.pages.device_monitor.no_scans_yet') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>

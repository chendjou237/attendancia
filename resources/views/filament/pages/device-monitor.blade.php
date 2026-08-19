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
                        'online' => ['dot' => '#22c55e', 'text' => '#22c55e', 'label' => 'Online'],
                        'warning' => ['dot' => '#f59e0b', 'text' => '#f59e0b', 'label' => 'Silent'],
                        'offline' => ['dot' => '#ef4444', 'text' => '#ef4444', 'label' => 'Offline'],
                    ][$device['status']];
                @endphp
                <x-filament::card>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem">
                        <div>
                            <div style="font-weight:600">{{ $device['serial'] }}</div>
                            <div style="font-size:0.8125rem;color:rgb(156 163 175)">{{ $device['corridor'] }}</div>
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
                            x-data="{
                                since: @js($device['last_seen_at']->toIso8601String()),
                                text: '',
                                tick() {
                                    const seconds = Math.max(0, Math.floor((Date.now() - new Date(this.since).getTime()) / 1000));
                                    if (seconds < 60) { this.text = seconds + 's ago'; }
                                    else if (seconds < 3600) { this.text = Math.floor(seconds / 60) + 'm ago'; }
                                    else if (seconds < 86400) { this.text = Math.floor(seconds / 3600) + 'h ago'; }
                                    else { this.text = Math.floor(seconds / 86400) + 'd ago'; }
                                },
                            }"
                            x-init="tick(); setInterval(() => tick(), 1000)"
                            x-text="'Last seen ' + text"
                        @endif
                    >
                        @if (! $device['last_seen_at'])
                            Never seen
                        @endif
                    </div>
                </x-filament::card>
            @empty
                <x-filament::card>
                    <span style="color:rgb(156 163 175)">No devices configured yet — add one under Devices.</span>
                </x-filament::card>
            @endforelse
        </div>

        {{-- Simulate scan --}}
        <x-filament::card>
            <div style="font-weight:600;margin-bottom:0.75rem">Simulate a scan</div>
            <p style="font-size:0.8125rem;color:rgb(156 163 175);margin-bottom:0.75rem">
                No device on site yet, or want to see the feed below move without walking to the corridor?
                Pick a device and an enrolled teacher and fire one real scan through the same pipeline a live
                terminal uses.
            </p>

            @if ($this->enrolledTeachers->isEmpty())
                <div style="font-size:0.8125rem;color:rgb(156 163 175)">
                    No teacher has a biometric ID enrolled yet — add one under Teacher Biometric IDs first.
                </div>
            @else
                <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:0.75rem">
                    <div style="flex:1;min-width:12rem">
                        <label style="display:block;font-size:0.8125rem;font-weight:500;margin-bottom:0.25rem">Device</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="selectedDeviceId">
                                @foreach ($this->devices as $device)
                                    <option value="{{ $device['id'] }}">{{ $device['serial'] }} — {{ $device['corridor'] }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div style="flex:1;min-width:12rem">
                        <label style="display:block;font-size:0.8125rem;font-weight:500;margin-bottom:0.25rem">Teacher</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="selectedEnrolmentId">
                                <option value="">Select a teacher…</option>
                                @foreach ($this->enrolledTeachers as $enrolment)
                                    <option value="{{ $enrolment->id }}">{{ $enrolment->teacher->full_name }} ({{ $enrolment->teacher->staff_no }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button wire:click="simulateScan" icon="heroicon-o-finger-print">
                        Simulate scan
                    </x-filament::button>
                </div>
            @endif
        </x-filament::card>

        {{-- Live activity feed --}}
        <x-filament::card>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem">
                <div style="font-weight:600">Live activity</div>
                <div style="font-size:0.75rem;color:rgb(113 113 122)">Updates every 3s</div>
            </div>

            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid rgb(63 63 70)">
                            <th style="padding:0.5rem">Time</th>
                            <th style="padding:0.5rem">Device</th>
                            <th style="padding:0.5rem">Teacher</th>
                            <th style="padding:0.5rem">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentEvents as $event)
                            <tr wire:key="raw-event-{{ $event->id }}" class="device-monitor-row-enter" style="border-bottom:1px solid rgb(39 39 42)">
                                <td style="padding:0.5rem;white-space:nowrap">{{ $event->effectiveTime()->format('H:i:s') }}</td>
                                <td style="padding:0.5rem">{{ $event->device?->serial ?? $event->device_serial }}</td>
                                <td style="padding:0.5rem">
                                    @if ($event->teacher)
                                        {{ $event->teacher->full_name }}
                                    @else
                                        <span style="color:rgb(156 163 175)">Unmatched ({{ $event->biometric_id ?? '—' }})</span>
                                    @endif
                                </td>
                                <td style="padding:0.5rem">
                                    @if ($event->sub_event_type === 38)
                                        <x-filament::badge color="success">Passed</x-filament::badge>
                                    @elseif ($event->sub_event_type === 49)
                                        <x-filament::badge color="danger">Failed</x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">Other</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:1rem;text-align:center;color:rgb(156 163 175)">
                                    No scans yet — simulate one above, or wait for the real device.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>

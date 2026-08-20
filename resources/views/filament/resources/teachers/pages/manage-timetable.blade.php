<x-filament-panels::page>
    {{--
        Filament's app.css is a purged bundle containing only the classes
        its own components use — arbitrary Tailwind utility classes on
        raw HTML here (this page has no Tailwind build of its own) are
        silently inert. Structure/spacing on plain elements below is
        therefore inline style, not utility classes; x-filament:: Blade
        components are untouched since those DO ship styled.
    --}}
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem">
        <div style="flex:1;min-width:16rem">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.25rem">
                {{ __('panel.resources.teachers.version') }}
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="versionId">
                    @forelse ($versions as $version)
                        <option value="{{ $version->id }}">
                            {{ $version->valid_from->translatedFormat('j F Y') }}
                            @if($version->valid_to) &ndash; {{ $version->valid_to->translatedFormat('j F Y') }} @endif
                            ({{ $version->state->getLabel() }})
                        </option>
                    @empty
                        <option value="">{{ __('panel.resources.teachers.no_versions') }}</option>
                    @endforelse
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div style="display:flex;align-items:flex-end;gap:0.5rem">
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.25rem">
                    {{ __('panel.resources.teachers.new_version_valid_from') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model="newVersionValidFrom" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button wire:click="createVersion" color="gray">
                {{ __('panel.resources.teachers.new_version_button') }}
                @if ($versionId)
                    ({{ __('panel.resources.teachers.copy_current') }})
                @endif
            </x-filament::button>
        </div>
    </div>

    @if ($versionId)
        <div style="margin-top:1.5rem;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                <thead>
                    <tr>
                        <th style="border:1px solid rgb(226 232 240 / 1);padding:0.5rem;text-align:left;width:4rem">{{ __('panel.common.period') }}</th>
                        @foreach ($days as $day)
                            <th style="border:1px solid rgb(226 232 240 / 1);padding:0.5rem;text-align:left;min-width:11rem">
                                {{ $this->dayLabel($day) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @for ($seq = 1; $seq <= $maxSeq; $seq++)
                        <tr>
                            <td style="border:1px solid rgb(226 232 240 / 1);padding:0.5rem;vertical-align:top;font-weight:500;color:rgb(107 114 128 / 1)">
                                {{ $seq }}
                            </td>
                            @foreach ($days as $day)
                                @php($slot = $slotGrid[$day][$seq] ?? null)
                                <td style="border:1px solid rgb(226 232 240 / 1);padding:0.5rem;vertical-align:top;{{ $slot?->is_break ? 'background-color:rgb(249 250 251 / 1)' : '' }}">
                                    @if ($slot === null)
                                        {{-- no period at this position for this day --}}
                                    @elseif ($slot->is_break)
                                        <span style="font-size:0.75rem;color:rgb(156 163 175 / 1)">{{ __('panel.resources.period_slots.is_break_short') }}</span>
                                        <div style="font-size:0.75rem;color:rgb(156 163 175 / 1)">{{ $slot->start_time }}&ndash;{{ $slot->end_time }}</div>
                                    @else
                                        <div style="font-size:0.75rem;color:rgb(156 163 175 / 1);margin-bottom:0.25rem">{{ $slot->start_time }}&ndash;{{ $slot->end_time }}</div>
                                        <div style="margin-bottom:0.25rem">
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select wire:model="cells.{{ $day }}.{{ $seq }}.class_code_id">
                                                    <option value="">&mdash;</option>
                                                    @foreach ($this->classCodeOptions as $classCode)
                                                        <option value="{{ $classCode->id }}">{{ $classCode->code }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </div>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="cells.{{ $day }}.{{ $seq }}.room_id">
                                                <option value="">&mdash;</option>
                                                @foreach ($this->roomOptions as $room)
                                                    <option value="{{ $room->id }}">{{ $room->corridor->code }}/{{ $room->code }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>

        <div style="margin-top:1rem">
            <x-filament::button wire:click="save">
                {{ __('panel.resources.teachers.save_timetable') }}
            </x-filament::button>
        </div>
    @else
        <p style="margin-top:1.5rem;color:rgb(107 114 128 / 1)">{{ __('panel.resources.teachers.no_timetable_version') }}</p>
    @endif
</x-filament-panels::page>

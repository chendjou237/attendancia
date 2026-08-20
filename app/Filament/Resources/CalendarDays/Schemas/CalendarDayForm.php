<?php

namespace App\Filament\Resources\CalendarDays\Schemas;

use App\Enums\DayType;
use App\Models\PeriodSlot;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CalendarDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label(__('panel.common.date'))
                    ->required(),
                Select::make('day_type')
                    ->label(__('panel.resources.calendar_days.day_type'))
                    ->options(DayType::class)
                    ->live()
                    ->required(),
                Textarea::make('note')
                    ->label(__('panel.common.note'))
                    ->columnSpanFull(),
                // §13.2: which slots survive on a half-day.
                Select::make('half_day_cutoff_slot_id')
                    ->label(__('panel.resources.calendar_days.half_day_cutoff'))
                    ->options(fn () => PeriodSlot::query()->where('is_break', false)->orderBy('day_of_week')->orderBy('seq')
                        ->get()->mapWithKeys(fn (PeriodSlot $slot) => [
                            $slot->id => __('panel.resources.calendar_days.slot_option', [
                                'day' => __('panel.days.'.$slot->day_of_week),
                                'period' => $slot->seq,
                                'start' => $slot->start_time,
                                'end' => $slot->end_time,
                            ]),
                        ]))
                    ->searchable()
                    // $get('day_type') resolves through the Select's enum
                    // options() to a DayType instance, not a raw string —
                    // compare against the case itself, not ->value.
                    ->visible(fn (Get $get) => $get('day_type') === DayType::HalfDay),
                // Decision (finding 1.7): scoped to class codes, not the
                // whole day. Leave empty to suspend the whole school.
                Select::make('suspendedClassCodes')
                    ->label(__('panel.resources.calendar_days.scoped_to'))
                    ->relationship('suspendedClassCodes', 'code')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText(__('panel.resources.calendar_days.suspended_help'))
                    ->visible(fn (Get $get) => $get('day_type') === DayType::ClassesSuspended),
            ]);
    }
}

<?php

namespace App\Filament\Resources\PeriodSlots\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PeriodSlotForm
{
    /**
     * Monday-first for the dropdown's visual order (matches the school
     * week), sourced from the single shared translated day-name list in
     * lang/{en,fr}/panel.php (Carbon convention: 0 = Sunday .. 6 =
     * Saturday) rather than a second hardcoded array — see
     * ManageTimetable::dayLabel() for the other previously-duplicated copy.
     */
    public static function dayOptions(): array
    {
        $days = __('panel.days');

        return [
            1 => $days[1], 2 => $days[2], 3 => $days[3],
            4 => $days[4], 5 => $days[5], 6 => $days[6],
            0 => $days[0],
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('day_of_week')
                    ->label(__('panel.common.day'))
                    ->options(self::dayOptions())
                    ->required(),
                TextInput::make('seq')
                    ->label(__('panel.resources.period_slots.sequence'))
                    ->numeric()
                    ->required()
                    ->helperText(__('panel.resources.period_slots.sequence_help')),
                TimePicker::make('start_time')
                    ->label(__('panel.common.start'))
                    ->required(),
                TimePicker::make('end_time')
                    ->label(__('panel.common.end'))
                    ->required()
                    ->after('start_time'),
                Toggle::make('is_break')
                    ->label(__('panel.resources.period_slots.is_break')),
                DatePicker::make('valid_from')
                    ->label(__('panel.common.valid_from'))
                    ->required()
                    ->helperText(__('panel.resources.period_slots.valid_from_help')),
                DatePicker::make('valid_to')
                    ->label(__('panel.common.valid_to'))
                    ->afterOrEqual('valid_from')
                    ->helperText(__('panel.resources.period_slots.valid_to_help')),
            ]);
    }
}

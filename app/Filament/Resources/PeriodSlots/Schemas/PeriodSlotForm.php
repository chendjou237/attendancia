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
    /** Carbon convention: 0 = Sunday .. 6 = Saturday. */
    public const DAY_OPTIONS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        0 => 'Sunday',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('day_of_week')
                    ->label('Day')
                    ->options(self::DAY_OPTIONS)
                    ->required(),
                TextInput::make('seq')
                    ->label('Sequence')
                    ->numeric()
                    ->required()
                    ->helperText('Order within the day (1, 2, 3…) — determines position in the bell-schedule grid.'),
                TimePicker::make('start_time')
                    ->required(),
                TimePicker::make('end_time')
                    ->required()
                    ->after('start_time'),
                Toggle::make('is_break')
                    ->label('Break / lunch period'),
                DatePicker::make('valid_from')
                    ->required()
                    ->helperText('Applies to this date onward. Existing computed results before this date are never rewritten.'),
                DatePicker::make('valid_to')
                    ->afterOrEqual('valid_from')
                    ->helperText('Leave empty if this is the current, open-ended version.'),
            ]);
    }
}

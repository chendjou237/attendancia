<?php

namespace App\Filament\Resources\Devices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('corridor_id')
                    ->relationship('corridor', 'name')
                    ->required(),
                TextInput::make('serial')
                    ->required(),
                TextInput::make('ip'),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('firmware'),
                DateTimePicker::make('last_seen_at')
                    ->disabled()
                    ->helperText('Written by the ingestion worker — not editable here.'),
                TextInput::make('last_time_offset_seconds')
                    ->numeric()
                    ->disabled()
                    ->suffix('s')
                    ->helperText('Device/server clock drift, written by the ingestion worker.'),
            ]);
    }
}

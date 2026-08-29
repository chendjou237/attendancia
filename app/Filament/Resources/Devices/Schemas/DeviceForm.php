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
                    ->label(__('panel.common.corridor'))
                    ->relationship('corridor', 'name')
                    ->required(),
                TextInput::make('serial')
                    ->label(__('panel.common.serial'))
                    ->required(),
                // datalist, not a Select: the known models are
                // suggestions, not a closed set — a corridor may get a
                // terminal this codebase has never heard of, and that
                // should still be recordable.
                TextInput::make('model')
                    ->label(__('panel.resources.devices.model'))
                    ->datalist(['DS-K1T8005EFX', 'DS-K1A8603'])
                    ->helperText(__('panel.resources.devices.model_help')),
                TextInput::make('ip')
                    ->label(__('panel.common.ip_address')),
                Toggle::make('is_active')
                    ->label(__('panel.common.active'))
                    ->required(),
                TextInput::make('firmware')
                    ->label(__('panel.resources.devices.firmware')),
                DateTimePicker::make('last_seen_at')
                    ->label(__('panel.resources.devices.last_seen_at'))
                    ->disabled()
                    ->helperText(__('panel.resources.devices.last_seen_at_help')),
                TextInput::make('last_time_offset_seconds')
                    ->label(__('panel.resources.devices.clock_offset'))
                    ->numeric()
                    ->disabled()
                    ->suffix('s')
                    ->helperText(__('panel.resources.devices.clock_offset_help')),
            ]);
    }
}

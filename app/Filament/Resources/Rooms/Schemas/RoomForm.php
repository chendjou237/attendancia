<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('corridor_id')
                    ->label(__('panel.common.corridor'))
                    ->relationship('corridor', 'name')
                    ->required(),
                TextInput::make('code')
                    ->label(__('panel.common.code'))
                    ->required(),
                TextInput::make('name')
                    ->label(__('panel.common.name'))
                    ->required(),
            ]);
    }
}

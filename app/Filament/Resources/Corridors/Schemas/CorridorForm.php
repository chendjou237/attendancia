<?php

namespace App\Filament\Resources\Corridors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CorridorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('panel.common.code'))
                    ->required(),
                TextInput::make('name')
                    ->label(__('panel.common.name'))
                    ->required(),
            ]);
    }
}

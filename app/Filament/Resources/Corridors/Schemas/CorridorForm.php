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
                    ->required(),
                TextInput::make('name')
                    ->required(),
            ]);
    }
}

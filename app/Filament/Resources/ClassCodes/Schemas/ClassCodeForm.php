<?php

namespace App\Filament\Resources\ClassCodes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClassCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('name_fr'),
            ]);
    }
}

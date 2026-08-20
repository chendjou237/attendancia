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
                    ->label(__('panel.common.code'))
                    ->required(),
                TextInput::make('name')
                    ->label(__('panel.common.name'))
                    ->required(),
                TextInput::make('name_fr')
                    ->label(__('panel.resources.class_codes.name_fr')),
            ]);
    }
}

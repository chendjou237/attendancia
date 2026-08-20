<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->confirmed()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->autocomplete('new-password')
                    ->helperText('Leave blank to keep the current password.'),
                TextInput::make('password_confirmation')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(false)
                    ->autocomplete('new-password'),
                Select::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->multiple(false)
                    ->preload()
                    ->required(),
                Select::make('locale')
                    ->label(__('panel.common.language'))
                    ->options([
                        'fr' => 'Français',
                        'en' => 'English',
                    ])
                    ->default('fr')
                    ->helperText('Leave blank to follow the site default / their own language switcher choice.'),
            ]);
    }
}

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
                    ->label(__('panel.common.name'))
                    ->required(),
                TextInput::make('email')
                    ->label(__('panel.resources.users.email'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label(__('panel.resources.users.password'))
                    ->password()
                    ->revealable()
                    ->confirmed()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->autocomplete('new-password')
                    ->helperText(__('panel.resources.users.password_help')),
                TextInput::make('password_confirmation')
                    ->label(__('panel.resources.users.password_confirmation'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(false)
                    ->autocomplete('new-password'),
                Select::make('roles')
                    ->label(__('panel.resources.users.role'))
                    ->relationship('roles', 'name')
                    // The role's own display name is already translated in
                    // attendance.php's 'roles' key (used elsewhere for the
                    // same admin/officer/principal/hr set) — reuse it here
                    // instead of showing Spatie's raw role.name.
                    ->getOptionLabelFromRecordUsing(fn ($record) => __('attendance.roles.'.$record->name))
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
                    ->helperText(__('panel.resources.users.locale_help')),
            ]);
    }
}

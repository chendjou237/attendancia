<?php

namespace App\Filament\Resources\TeacherBiometricIds\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeacherBiometricIdForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('teacher_id')
                    ->relationship('teacher', 'full_name')
                    ->searchable()
                    ->required(),
                TextInput::make('biometric_id')
                    ->label('Biometric ID (employeeNoString)')
                    ->required()
                    ->helperText('The device-side id — confirm the exact field name and value against a real scan first (§13.5).'),
                DatePicker::make('valid_from')
                    ->required()
                    ->default(now()),
                DatePicker::make('valid_to')
                    ->helperText('Leave empty for an active mapping. Assigning this id to a different teacher automatically closes this one out.'),
            ]);
    }
}

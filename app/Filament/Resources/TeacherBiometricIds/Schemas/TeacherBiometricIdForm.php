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
                    ->label(__('panel.common.teacher'))
                    ->relationship('teacher', 'full_name')
                    ->searchable()
                    ->required(),
                TextInput::make('biometric_id')
                    ->label(__('panel.resources.teacher_biometric_ids.biometric_id'))
                    ->required()
                    ->helperText(__('panel.resources.teacher_biometric_ids.biometric_id_help')),
                DatePicker::make('valid_from')
                    ->label(__('panel.common.valid_from'))
                    ->required()
                    ->default(now()),
                DatePicker::make('valid_to')
                    ->label(__('panel.common.valid_to'))
                    ->helperText(__('panel.resources.teacher_biometric_ids.valid_to_help')),
            ]);
    }
}

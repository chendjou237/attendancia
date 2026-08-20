<?php

namespace App\Filament\Resources\Teachers\Schemas;

use App\Enums\EmploymentType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeacherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('staff_no')
                    ->label(__('panel.common.staff_no'))
                    ->required(),
                TextInput::make('full_name')
                    ->label(__('panel.common.full_name'))
                    ->required(),
                Select::make('employment_type')
                    ->label(__('panel.common.employment_type'))
                    ->options(EmploymentType::class)
                    ->required(),
                DatePicker::make('active_from')
                    ->label(__('panel.resources.teachers.active_from'))
                    ->required(),
                DatePicker::make('active_to')
                    ->label(__('panel.resources.teachers.active_to')),
            ]);
    }
}

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
                    ->required(),
                TextInput::make('full_name')
                    ->required(),
                Select::make('employment_type')
                    ->options(EmploymentType::class)
                    ->required(),
                DatePicker::make('active_from')
                    ->required(),
                DatePicker::make('active_to'),
            ]);
    }
}

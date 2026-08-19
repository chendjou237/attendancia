<?php

namespace App\Filament\Resources\TeacherBiometricIds\Pages;

use App\Filament\Resources\TeacherBiometricIds\TeacherBiometricIdResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeacherBiometricIds extends ListRecords
{
    protected static string $resource = TeacherBiometricIdResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

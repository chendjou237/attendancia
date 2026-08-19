<?php

namespace App\Filament\Resources\TeacherBiometricIds\Pages;

use App\Filament\Resources\TeacherBiometricIds\TeacherBiometricIdResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeacherBiometricId extends EditRecord
{
    protected static string $resource = TeacherBiometricIdResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

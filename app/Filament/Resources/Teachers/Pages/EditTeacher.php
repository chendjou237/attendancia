<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Filament\Actions\GuardedDelete;
use App\Filament\Resources\Teachers\Actions\TeacherStatusActions;
use App\Filament\Resources\Teachers\TeacherResource;
use Filament\Resources\Pages\EditRecord;

class EditTeacher extends EditRecord
{
    protected static string $resource = TeacherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TeacherStatusActions::deactivate(),
            TeacherStatusActions::merge(),
            TeacherStatusActions::reactivate(),
            GuardedDelete::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\ClassCodes\Pages;

use App\Filament\Resources\ClassCodes\ClassCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClassCode extends EditRecord
{
    protected static string $resource = ClassCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

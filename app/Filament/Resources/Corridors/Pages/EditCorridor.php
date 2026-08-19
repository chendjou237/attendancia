<?php

namespace App\Filament\Resources\Corridors\Pages;

use App\Filament\Resources\Corridors\CorridorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCorridor extends EditRecord
{
    protected static string $resource = CorridorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

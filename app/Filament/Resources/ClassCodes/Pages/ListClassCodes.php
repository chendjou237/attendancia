<?php

namespace App\Filament\Resources\ClassCodes\Pages;

use App\Filament\Resources\ClassCodes\ClassCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClassCodes extends ListRecords
{
    protected static string $resource = ClassCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

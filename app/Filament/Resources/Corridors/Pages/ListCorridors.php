<?php

namespace App\Filament\Resources\Corridors\Pages;

use App\Filament\Resources\Corridors\CorridorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCorridors extends ListRecords
{
    protected static string $resource = CorridorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

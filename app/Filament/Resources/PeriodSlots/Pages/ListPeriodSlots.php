<?php

namespace App\Filament\Resources\PeriodSlots\Pages;

use App\Filament\Resources\PeriodSlots\PeriodSlotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPeriodSlots extends ListRecords
{
    protected static string $resource = PeriodSlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

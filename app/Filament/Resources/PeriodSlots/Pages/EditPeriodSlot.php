<?php

namespace App\Filament\Resources\PeriodSlots\Pages;

use App\Filament\Resources\PeriodSlots\PeriodSlotResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPeriodSlot extends EditRecord
{
    protected static string $resource = PeriodSlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\CalendarDays\Pages;

use App\Filament\Resources\CalendarDays\CalendarDayResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Artisan;

class CreateCalendarDay extends CreateRecord
{
    protected static string $resource = CalendarDayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['marked_by'] = auth()->id();
        $data['marked_at'] = now();

        return $data;
    }

    /**
     * §8.1: declaring a closure/suspension recomputes every teacher's
     * results for that date — this is the normal path, not an edge case.
     */
    protected function afterCreate(): void
    {
        Artisan::call('attendance:compute', ['date' => $this->record->date->toDateString()]);
    }
}

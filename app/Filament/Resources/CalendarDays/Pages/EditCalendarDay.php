<?php

namespace App\Filament\Resources\CalendarDays\Pages;

use App\Filament\Resources\CalendarDays\CalendarDayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;

class EditCalendarDay extends EditRecord
{
    protected static string $resource = CalendarDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Deleting reverts the date to an ordinary teaching day, which
            // also needs a recompute — capture the date before the row is
            // gone rather than relying on $this->record afterwards.
            DeleteAction::make()
                ->after(function () {
                    Artisan::call('attendance:compute', ['date' => $this->record->date->toDateString()]);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['marked_by'] = auth()->id();
        $data['marked_at'] = now();

        return $data;
    }

    /**
     * §8.1: retro-marking a day (e.g. a closure declared the night
     * before, after some results already exist) must recompute — this
     * is what makes that automatic instead of a manual follow-up step.
     */
    protected function afterSave(): void
    {
        Artisan::call('attendance:compute', ['date' => $this->record->date->toDateString()]);
    }
}

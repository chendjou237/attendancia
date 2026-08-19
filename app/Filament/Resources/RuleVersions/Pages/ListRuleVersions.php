<?php

namespace App\Filament\Resources\RuleVersions\Pages;

use App\Filament\Resources\RuleVersions\RuleVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRuleVersions extends ListRecords
{
    protected static string $resource = RuleVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

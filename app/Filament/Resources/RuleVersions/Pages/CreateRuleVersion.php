<?php

namespace App\Filament\Resources\RuleVersions\Pages;

use App\Filament\Resources\RuleVersions\RuleVersionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRuleVersion extends CreateRecord
{
    protected static string $resource = RuleVersionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}

<?php

namespace App\Filament\Resources\PeriodSlots;

use App\Filament\Resources\PeriodSlots\Pages\CreatePeriodSlot;
use App\Filament\Resources\PeriodSlots\Pages\EditPeriodSlot;
use App\Filament\Resources\PeriodSlots\Pages\ListPeriodSlots;
use App\Filament\Resources\PeriodSlots\Schemas\PeriodSlotForm;
use App\Filament\Resources\PeriodSlots\Tables\PeriodSlotsTable;
use App\Models\PeriodSlot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PeriodSlotResource extends Resource
{
    protected static ?string $model = PeriodSlot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function form(Schema $schema): Schema
    {
        return PeriodSlotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PeriodSlotsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeriodSlots::route('/'),
            'create' => CreatePeriodSlot::route('/create'),
            'edit' => EditPeriodSlot::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('panel.nav.period_slots.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.nav.period_slots.plural');
    }
}

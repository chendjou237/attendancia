<?php

namespace App\Filament\Resources\Corridors;

use App\Filament\Resources\Corridors\Pages\CreateCorridor;
use App\Filament\Resources\Corridors\Pages\EditCorridor;
use App\Filament\Resources\Corridors\Pages\ListCorridors;
use App\Filament\Resources\Corridors\Schemas\CorridorForm;
use App\Filament\Resources\Corridors\Tables\CorridorsTable;
use App\Models\Corridor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CorridorResource extends Resource
{
    protected static ?string $model = Corridor::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CorridorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorridorsTable::configure($table);
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
            'index' => ListCorridors::route('/'),
            'create' => CreateCorridor::route('/create'),
            'edit' => EditCorridor::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}

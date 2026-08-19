<?php

namespace App\Filament\Resources\ClassCodes;

use App\Filament\Resources\ClassCodes\Pages\CreateClassCode;
use App\Filament\Resources\ClassCodes\Pages\EditClassCode;
use App\Filament\Resources\ClassCodes\Pages\ListClassCodes;
use App\Filament\Resources\ClassCodes\Schemas\ClassCodeForm;
use App\Filament\Resources\ClassCodes\Tables\ClassCodesTable;
use App\Models\ClassCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClassCodeResource extends Resource
{
    protected static ?string $model = ClassCode::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ClassCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClassCodesTable::configure($table);
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
            'index' => ListClassCodes::route('/'),
            'create' => CreateClassCode::route('/create'),
            'edit' => EditClassCode::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}

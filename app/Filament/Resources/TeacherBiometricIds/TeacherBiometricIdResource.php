<?php

namespace App\Filament\Resources\TeacherBiometricIds;

use App\Filament\Resources\TeacherBiometricIds\Pages\CreateTeacherBiometricId;
use App\Filament\Resources\TeacherBiometricIds\Pages\EditTeacherBiometricId;
use App\Filament\Resources\TeacherBiometricIds\Pages\ListTeacherBiometricIds;
use App\Filament\Resources\TeacherBiometricIds\Schemas\TeacherBiometricIdForm;
use App\Filament\Resources\TeacherBiometricIds\Tables\TeacherBiometricIdsTable;
use App\Models\TeacherBiometricId;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TeacherBiometricIdResource extends Resource
{
    protected static ?string $model = TeacherBiometricId::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return TeacherBiometricIdForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeacherBiometricIdsTable::configure($table);
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
            'index' => ListTeacherBiometricIds::route('/'),
            'create' => CreateTeacherBiometricId::route('/create'),
            'edit' => EditTeacherBiometricId::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('panel.nav.teacher_biometric_ids.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.nav.teacher_biometric_ids.plural');
    }
}

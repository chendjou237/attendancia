<?php

namespace App\Filament\Resources\RuleVersions;

use App\Filament\Resources\RuleVersions\Pages\CreateRuleVersion;
use App\Filament\Resources\RuleVersions\Pages\ListRuleVersions;
use App\Filament\Resources\RuleVersions\Schemas\RuleVersionForm;
use App\Filament\Resources\RuleVersions\Tables\RuleVersionsTable;
use App\Models\RuleVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing a rule_version after it's already computed results would
 * retroactively change what "rule_version_id #4" is claimed to mean —
 * the same class of mistake §5 forbids for migrations. A change of
 * mind means creating a new version, not mutating this one.
 */
class RuleVersionResource extends Resource
{
    protected static ?string $model = RuleVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return RuleVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RuleVersionsTable::configure($table);
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
            'index' => ListRuleVersions::route('/'),
            'create' => CreateRuleVersion::route('/create'),
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('panel.nav.rule_versions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.nav.rule_versions.plural');
    }
}

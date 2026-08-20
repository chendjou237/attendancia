<?php

namespace App\Filament\Resources\MonthlyReports;

use App\Filament\Resources\MonthlyReports\Pages\ListMonthlyReports;
use App\Filament\Resources\MonthlyReports\Pages\ViewMonthlyReport;
use App\Filament\Resources\MonthlyReports\Tables\MonthlyReportsTable;
use App\Models\MonthlyReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * §10/Phase D: monthly totals, one row per calendar month, moving
 * through draft -> officer reviewed -> principal approved -> sent to
 * HR. There is no create/edit form — a report is computed from
 * period_results via MonthlyReportGenerator (ListMonthlyReports'
 * "Generate" action), never hand-entered, and once approved it's
 * frozen (§3's "a November edit never rewrites September's pay").
 */
class MonthlyReportResource extends Resource
{
    protected static ?string $model = MonthlyReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    public static function table(Table $table): Table
    {
        return MonthlyReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonthlyReports::route('/'),
            'view' => ViewMonthlyReport::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * All four roles have a real reason to be here (docs/onboarding.md
     * §1-4): Officer generates/reviews, Principal approves, HR reads
     * the finished ones, Admin oversees. Generating is separately
     * restricted to admin/officer on the header action itself
     * (ListMonthlyReports) — viewing the list isn't the same as being
     * allowed to kick off a new computation.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer', 'principal', 'hr']) ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('panel.nav.monthly_reports.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.nav.monthly_reports.plural');
    }
}

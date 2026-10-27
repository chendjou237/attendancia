<?php

namespace App\Filament\Pages;

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Filament\Actions\RecomputeAttendanceAction;
use App\Filament\Tables\AttendanceFilters;
use App\Models\PeriodResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Closes a gap flagged from real use of the system: no screen let staff
 * search a single teacher and see their period-by-period presence for a
 * day or a range — ExceptionQueue only ever shows *pending* items, and
 * MonthlyReportResource only shows aggregated monthly totals. This page
 * reads the same current PeriodResult rows everything else already
 * computes; no new business logic, and deliberately no override action
 * (that stays ExceptionQueue's job, so there is exactly one place an
 * override is made).
 *
 * Defaults to today with no teacher selected, so it doubles as a live
 * "who's actually teaching right now" daily roster; picking a teacher
 * narrows to one person, widening the date range serves historical
 * lookup.
 */
class TeacherAttendance extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected string $view = 'filament.pages.teacher-attendance';

    /**
     * Onboarding §2/§3: same three roles as ExceptionQueue. HR's whole
     * role is scoped to Monthly Reports and has no reason to see
     * day-level operational screens.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer', 'principal']) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.nav.teacher_attendance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('panel.nav.teacher_attendance');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [RecomputeAttendanceAction::make()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PeriodResult::query()
                    ->current()
                    ->with(['teacher', 'slot', 'classCode', 'session', 'overrideBy'])
            )
            // Standalone Page, not a Resource — Filament's empty-state
            // otherwise auto-derives an untranslated "period results"
            // from the model class name, since that inference only
            // routes through getModelLabel()/getPluralModelLabel() on
            // an actual Resource, not a raw ->query() on a custom page.
            ->emptyStateHeading(__('panel.pages.teacher_attendance.empty_state'))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label(__('panel.common.date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('teacher.full_name')
                    ->label(__('panel.common.teacher'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slot.seq')
                    ->label(__('panel.common.period'))
                    ->sortable(),
                TextColumn::make('slot.start_time')
                    ->label(__('panel.common.start'))
                    ->time('H:i'),
                TextColumn::make('slot.end_time')
                    ->label(__('panel.common.end'))
                    ->time('H:i'),
                TextColumn::make('classCode.code')
                    ->label(__('panel.common.class')),
                TextColumn::make('status')
                    ->label(__('panel.common.status'))
                    ->state(fn (PeriodResult $record): PeriodStatus => $record->effectiveStatus())
                    ->badge()
                    ->color(fn (PeriodStatus $state): string => match ($state) {
                        PeriodStatus::Present, PeriodStatus::PresentAdmin => 'success',
                        PeriodStatus::AbsentJustified, PeriodStatus::Unpaired, PeriodStatus::LocationMismatch => 'warning',
                        PeriodStatus::Absent => 'danger',
                    }),
                TextColumn::make('source')
                    ->label(__('panel.common.source'))
                    ->badge(),
                TextColumn::make('session.anomaly_code')
                    ->label(__('panel.common.why'))
                    ->badge()
                    ->placeholder(__('panel.common.dash_placeholder'))
                    ->formatStateUsing(fn (?string $state): ?string => filled($state)
                        ? (SessionAnomaly::tryFrom($state)?->getLabel() ?? $state)
                        : null),
                IconColumn::make('override_status')
                    ->label(__('panel.common.override'))
                    ->icon(fn (?PeriodStatus $state): ?BackedEnum => filled($state)
                        ? Heroicon::OutlinedPencilSquare
                        : null)
                    ->color('warning')
                    ->tooltip(fn (PeriodResult $record): ?string => filled($record->override_status)
                        ? __('panel.pages.teacher_attendance.override_tooltip', [
                            'status' => $record->override_status->getLabel(),
                            'reason' => $record->override_reason,
                            'actor' => $record->overrideBy?->name ?? __('panel.pages.teacher_attendance.unknown_actor'),
                            'at' => $record->override_at?->format('d/m/Y H:i'),
                        ])
                        : null),
            ])
            ->filters([
                AttendanceFilters::teacher(),
                AttendanceFilters::status(),
                AttendanceFilters::reason(),
                AttendanceFilters::dateRange(defaultToday: true),
            ]);
    }
}

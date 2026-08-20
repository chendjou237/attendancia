<?php

namespace App\Filament\Pages;

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Models\PeriodResult;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
                SelectFilter::make('teacher')
                    ->label(__('panel.common.teacher'))
                    ->relationship('teacher', 'full_name')
                    ->searchable()
                    ->preload(),

                Filter::make('date_range')
                    ->label(__('panel.pages.teacher_attendance.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('panel.common.from'))
                            ->default(now()->toDateString())
                            ->native(false),
                        DatePicker::make('to')
                            ->label(__('panel.common.to'))
                            ->default(now()->toDateString())
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                // Carbon::parse(...)->toDateString(), not the
                                // raw filter value: the DatePicker's state
                                // comes back as a full datetime string
                                // ("2026-08-20 21:58:24") even though only a
                                // date was ever entered. whereDate() compares
                                // its column-side date() extraction against
                                // the value AS GIVEN — a bare date string
                                // against a full datetime string is a losing
                                // string comparison ("2026-08-20" < "2026-08-20 21:58:24"),
                                // so every row silently vanishes unless the
                                // value side is normalised to match.
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', Carbon::parse($date)->toDateString()),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', Carbon::parse($date)->toDateString()),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make(__('panel.pages.teacher_attendance.indicator_from', ['date' => Carbon::parse($data['from'])->format('d/m/Y')]))
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make(__('panel.pages.teacher_attendance.indicator_until', ['date' => Carbon::parse($data['to'])->format('d/m/Y')]))
                                ->removeField('to');
                        }

                        return $indicators;
                    }),
            ]);
    }
}

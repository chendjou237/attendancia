<?php

namespace App\Filament\Tables;

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Table filters for any screen listing period_results — TeacherAttendance
 * and ExceptionQueue — so the two can't drift apart in how they read a
 * date or a status.
 */
class AttendanceFilters
{
    public static function teacher(): SelectFilter
    {
        return SelectFilter::make('teacher')
            ->label(__('panel.common.teacher'))
            ->relationship('teacher', 'full_name')
            ->searchable()
            ->preload();
    }

    /**
     * Filters on the effective status (override_status ?? status), the
     * same value the Status column shows — a period a human overrode from
     * Unpaired to Present must be found under Present, not under Unpaired.
     */
    public static function status(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('panel.common.status'))
            ->multiple()
            ->options(collect(PeriodStatus::cases())
                ->mapWithKeys(fn (PeriodStatus $status): array => [$status->value => $status->getLabel()])
                ->all())
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['values'] ?? null,
                fn (Builder $query, array $values): Builder => $query->where(fn (Builder $query): Builder => $query
                    ->whereIn('override_status', $values)
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->whereNull('override_status')
                        ->whereIn('status', $values))),
            ));
    }

    public static function reason(): SelectFilter
    {
        return SelectFilter::make('reason')
            ->label(__('panel.common.why'))
            ->multiple()
            ->options(collect(SessionAnomaly::cases())
                ->mapWithKeys(fn (SessionAnomaly $anomaly): array => [$anomaly->value => $anomaly->getLabel()])
                ->all())
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['values'] ?? null,
                fn (Builder $query, array $values): Builder => $query
                    ->whereHas('session', fn (Builder $query): Builder => $query->whereIn('anomaly_code', $values)),
            ));
    }

    public static function classCode(): SelectFilter
    {
        return SelectFilter::make('class_code')
            ->label(__('panel.common.class'))
            ->relationship('classCode', 'code')
            ->searchable()
            ->preload();
    }

    /**
     * $defaultToday: TeacherAttendance opens on today's roster; the
     * Exception Queue must open on everything still pending, however old.
     */
    public static function dateRange(bool $defaultToday): Filter
    {
        $default = $defaultToday ? now()->toDateString() : null;

        return Filter::make('date_range')
            ->label(__('panel.pages.teacher_attendance.date_range'))
            ->schema([
                DatePicker::make('from')
                    ->label(__('panel.common.from'))
                    ->default($default)
                    ->native(false),
                DatePicker::make('to')
                    ->label(__('panel.common.to'))
                    ->default($default)
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
                        // date was ever entered. The column holds a
                        // bare "Y-m-d" (App\Casts\DateOnly), and
                        // comparing that against a full datetime
                        // string is a losing comparison
                        // ("2026-08-20" < "2026-08-20 21:58:24"), so
                        // every row silently vanishes unless the value
                        // side is normalised to match.
                        fn (Builder $query, $date): Builder => $query->where('date', '>=', Carbon::parse($date)->toDateString()),
                    )
                    ->when(
                        $data['to'] ?? null,
                        fn (Builder $query, $date): Builder => $query->where('date', '<=', Carbon::parse($date)->toDateString()),
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
            });
    }
}

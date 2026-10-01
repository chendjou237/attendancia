<?php

namespace App\Filament\Resources\Teachers\Tables;

use App\Filament\Actions\GuardedDelete;
use App\Filament\Resources\Teachers\Actions\TeacherStatusActions;
use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\Teacher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeachersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('mergedInto'))
            ->columns([
                TextColumn::make('staff_no')
                    ->label(__('panel.common.staff_no'))
                    ->searchable(),
                TextColumn::make('full_name')
                    ->label(__('panel.common.full_name'))
                    ->searchable(),
                TextColumn::make('employment_type')
                    ->label(__('panel.common.employment_type'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('panel.resources.teachers.status'))
                    ->badge()
                    ->state(fn (Teacher $record): string => match (self::status($record)) {
                        'merged' => __('panel.resources.teachers.status_merged', ['name' => $record->mergedInto?->full_name]),
                        'inactive' => __('panel.resources.teachers.status_inactive'),
                        default => __('panel.resources.teachers.status_active'),
                    })
                    ->color(fn (Teacher $record): string => match (self::status($record)) {
                        'merged' => 'warning',
                        'inactive' => 'gray',
                        default => 'success',
                    }),
                TextColumn::make('active_from')
                    ->label(__('panel.resources.teachers.active_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('active_to')
                    ->label(__('panel.resources.teachers.active_to'))
                    ->date()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('panel.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('panel.common.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Active by default: a deactivated duplicate listed next to
                // the real teacher under the same name is the confusion that
                // made this filter necessary.
                TernaryFilter::make('active')
                    ->label(__('panel.resources.teachers.status'))
                    ->placeholder(__('panel.resources.teachers.filter_all'))
                    ->trueLabel(__('panel.resources.teachers.status_active'))
                    ->falseLabel(__('panel.resources.teachers.status_inactive'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->whereNull('merged_into_id')
                            ->where(fn (Builder $q) => $q
                                ->whereNull('active_to')
                                ->orWhere('active_to', '>=', self::today())),
                        false: fn (Builder $query) => $query
                            ->where(fn (Builder $q) => $q
                                ->whereNotNull('merged_into_id')
                                ->orWhere('active_to', '<', self::today())),
                    ),
            ])
            ->recordActions([
                Action::make('timetable')
                    ->label(__('panel.resources.teachers.timetable_action'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Teacher $record) => TeacherResource::getUrl('timetable', ['record' => $record])),
                EditAction::make(),
                ActionGroup::make([
                    TeacherStatusActions::deactivate(),
                    TeacherStatusActions::merge(),
                    TeacherStatusActions::reactivate(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDelete::bulk(),
                ]),
            ]);
    }

    private static function status(Teacher $record): string
    {
        return match (true) {
            $record->merged_into_id !== null => 'merged',
            ! $record->isActiveOn(now(config('attendance.timezone'))) => 'inactive',
            default => 'active',
        };
    }

    private static function today(): string
    {
        return now(config('attendance.timezone'))->toDateString();
    }
}

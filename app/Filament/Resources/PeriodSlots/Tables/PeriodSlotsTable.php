<?php

namespace App\Filament\Resources\PeriodSlots\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PeriodSlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Grid order, not insertion order — the whole point of this
            // screen is to read like the paper bell-schedule grid.
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('day_of_week')->orderBy('seq'))
            ->columns([
                TextColumn::make('day_of_week')
                    ->label(__('panel.common.day'))
                    ->formatStateUsing(fn ($state): string => __('panel.days.'.(int) $state))
                    ->sortable(),
                TextColumn::make('seq')
                    ->label(__('panel.resources.period_slots.seq'))
                    ->sortable(),
                TextColumn::make('start_time')
                    ->label(__('panel.common.start'))
                    ->time('H:i')
                    ->sortable(),
                TextColumn::make('end_time')
                    ->label(__('panel.common.end'))
                    ->time('H:i')
                    ->sortable(),
                IconColumn::make('is_break')
                    ->label(__('panel.resources.period_slots.is_break_short'))
                    ->boolean(),
                TextColumn::make('valid_from')
                    ->label(__('panel.common.valid_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->label(__('panel.common.valid_to'))
                    ->date()
                    ->placeholder(__('panel.resources.period_slots.open_ended'))
                    ->sortable(),
            ])
            ->filters([
                // Same versioning as TimetableVersion / RuleVersion: several
                // valid_from generations of the same (day, seq) can coexist,
                // so the list defaults to today's active grid rather than
                // every historical row at once. now()->toDateString(), not
                // a bare now(): the columns hold "Y-m-d" (App\Casts\DateOnly),
                // and comparing one against a full "Y-m-d H:i:s" would drop a
                // schedule on its own final day.
                Filter::make('current_only')
                    ->label(__('panel.resources.period_slots.current_only'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('valid_from', '<=', now()->toDateString())
                        ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString())))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

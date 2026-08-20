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
                // every historical row at once. whereDate(), not a plain
                // <=/>= comparison — see PeriodSlot::forDate() for why a row
                // whose valid_from is today would otherwise be excluded.
                Filter::make('current_only')
                    ->label(__('panel.resources.period_slots.current_only'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('valid_from', '<=', now())
                        ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', now())))
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

<?php

namespace App\Filament\Resources\PeriodSlots\Tables;

use App\Filament\Resources\PeriodSlots\Schemas\PeriodSlotForm;
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
                    ->label('Day')
                    ->formatStateUsing(fn ($state): string => PeriodSlotForm::DAY_OPTIONS[(int) $state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('seq')
                    ->label('Seq')
                    ->sortable(),
                TextColumn::make('start_time')
                    ->time('H:i')
                    ->sortable(),
                TextColumn::make('end_time')
                    ->time('H:i')
                    ->sortable(),
                IconColumn::make('is_break')
                    ->label('Break')
                    ->boolean(),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->date()
                    ->placeholder('Open-ended')
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
                    ->label('Current version only')
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

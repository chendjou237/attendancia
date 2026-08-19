<?php

namespace App\Filament\Resources\CalendarDays\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CalendarDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('day_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('suspendedClassCodes.code')
                    ->label('Scoped to')
                    ->badge()
                    ->placeholder('Whole school'),
                TextColumn::make('note')
                    ->limit(40),
                TextColumn::make('markedBy.name')
                    ->label('Marked by'),
                TextColumn::make('marked_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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

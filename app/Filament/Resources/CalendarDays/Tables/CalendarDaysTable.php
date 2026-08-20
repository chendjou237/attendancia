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
                    ->label(__('panel.common.date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('day_type')
                    ->label(__('panel.resources.calendar_days.day_type'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('suspendedClassCodes.code')
                    ->label(__('panel.resources.calendar_days.scoped_to'))
                    ->badge()
                    ->placeholder(__('panel.resources.calendar_days.whole_school')),
                TextColumn::make('note')
                    ->label(__('panel.common.note'))
                    ->limit(40),
                TextColumn::make('markedBy.name')
                    ->label(__('panel.common.marked_by')),
                TextColumn::make('marked_at')
                    ->label(__('panel.resources.calendar_days.marked_at'))
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

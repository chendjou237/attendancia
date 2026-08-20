<?php

namespace App\Filament\Resources\Notices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NoticesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('teacher.full_name')
                    ->label(__('panel.common.teacher'))
                    ->searchable(),
                TextColumn::make('type')
                    // Not translated here — see NoticeForm::types() and
                    // the note in ClassCodeForm's neighbouring resources:
                    // this column has no formatStateUsing at all today
                    // (pre-existing, shows the raw 'sick_leave' value
                    // regardless of locale) and fixing that is flagged as
                    // a separate follow-up, not silently bundled in here.
                    ->label(__('panel.resources.notices.type'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('reference')
                    ->label(__('panel.resources.notices.reference'))
                    ->searchable(),
                TextColumn::make('createdBy.name')
                    ->label(__('panel.common.recorded_by')),
                TextColumn::make('created_at')
                    ->label(__('panel.common.created_at'))
                    ->dateTime()
                    ->sortable(),
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

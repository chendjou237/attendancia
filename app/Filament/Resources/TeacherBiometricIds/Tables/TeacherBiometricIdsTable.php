<?php

namespace App\Filament\Resources\TeacherBiometricIds\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeacherBiometricIdsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('teacher.full_name')
                    ->label(__('panel.common.teacher'))
                    ->searchable(),
                TextColumn::make('biometric_id')
                    ->label(__('panel.resources.teacher_biometric_ids.biometric_id'))
                    ->searchable(),
                TextColumn::make('valid_from')
                    ->label(__('panel.common.valid_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->label(__('panel.common.valid_to'))
                    ->date()
                    ->sortable()
                    ->placeholder(__('panel.resources.teacher_biometric_ids.active_placeholder')),
                IconColumn::make('is_active')
                    ->label(__('panel.common.active'))
                    ->boolean()
                    ->state(fn ($record) => $record->valid_to === null),
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

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
                    ->label('Teacher')
                    ->searchable(),
                TextColumn::make('biometric_id')
                    ->searchable(),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->date()
                    ->sortable()
                    ->placeholder('Active'),
                IconColumn::make('is_active')
                    ->label('Active')
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

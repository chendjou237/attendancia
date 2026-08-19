<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('entity')
                    ->searchable(),
                TextColumn::make('entity_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->searchable()
                    ->placeholder('System'),
            ])
            ->filters([
                //
            ]);
    }
}

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
                    ->label(__('panel.common.date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('entity')
                    ->label(__('panel.resources.audit_logs.entity'))
                    ->searchable(),
                TextColumn::make('entity_id')
                    ->label(__('panel.resources.audit_logs.entity_id'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('panel.resources.audit_logs.action'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('actor.name')
                    ->label(__('panel.resources.audit_logs.actor'))
                    ->searchable()
                    ->placeholder(__('panel.resources.audit_logs.system')),
            ])
            ->filters([
                //
            ]);
    }
}

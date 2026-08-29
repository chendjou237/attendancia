<?php

namespace App\Filament\Resources\Devices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('corridor.name')
                    ->label(__('panel.common.corridor'))
                    ->searchable(),
                TextColumn::make('serial')
                    ->label(__('panel.common.serial'))
                    ->searchable(),
                TextColumn::make('model')
                    ->label(__('panel.resources.devices.model'))
                    ->placeholder(__('panel.common.dash_placeholder'))
                    ->searchable(),
                TextColumn::make('ip')
                    ->label(__('panel.common.ip_address'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('panel.common.active'))
                    ->boolean(),
                TextColumn::make('firmware')
                    ->label(__('panel.resources.devices.firmware'))
                    ->searchable(),
                TextColumn::make('last_seen_at')
                    ->label(__('panel.resources.devices.last_seen_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_time_offset_seconds')
                    ->label(__('panel.resources.devices.clock_offset'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('panel.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('panel.common.updated_at'))
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

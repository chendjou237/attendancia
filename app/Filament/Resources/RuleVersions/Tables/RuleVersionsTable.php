<?php

namespace App\Filament\Resources\RuleVersions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RuleVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('grace_late_minutes')->label('Grace (late)')->numeric()->suffix(' min'),
                TextColumn::make('grace_early_minutes')->label('Grace (early)')->numeric()->suffix(' min'),
                TextColumn::make('pair_window_before_minutes')->label('Pair window (before)')->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pair_window_after_minutes')->label('Pair window (after)')->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_scan_gap_seconds')->label('Debounce')->numeric()->suffix(' s')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_session_minutes')->label('Min. session')->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hours_per_period')->label('Hours/period')->numeric(),
                TextColumn::make('createdBy.name')->label('Created by'),
                TextColumn::make('note')->limit(40),
            ])
            ->filters([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

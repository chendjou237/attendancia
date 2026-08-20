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
                    ->label(__('panel.common.valid_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('grace_late_minutes')->label(__('panel.resources.rule_versions.grace_late'))->numeric()->suffix(' min'),
                TextColumn::make('grace_early_minutes')->label(__('panel.resources.rule_versions.grace_early'))->numeric()->suffix(' min'),
                TextColumn::make('pair_window_before_minutes')->label(__('panel.resources.rule_versions.pair_window_before'))->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pair_window_after_minutes')->label(__('panel.resources.rule_versions.pair_window_after'))->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_scan_gap_seconds')->label(__('panel.resources.rule_versions.debounce'))->numeric()->suffix(' s')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_session_minutes')->label(__('panel.resources.rule_versions.min_session'))->numeric()->suffix(' min')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hours_per_period')->label(__('panel.resources.rule_versions.hours_per_period'))->numeric(),
                TextColumn::make('createdBy.name')->label(__('panel.common.created_by')),
                TextColumn::make('note')->label(__('panel.common.note'))->limit(40),
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

<?php

namespace App\Filament\Resources\RuleVersions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class RuleVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('grace_late_minutes')
                    ->label(__('panel.resources.rule_versions.grace_late'))
                    ->required()
                    ->numeric(),
                TextInput::make('grace_early_minutes')
                    ->label(__('panel.resources.rule_versions.grace_early'))
                    ->required()
                    ->numeric(),
                TextInput::make('pair_window_before_minutes')
                    ->label(__('panel.resources.rule_versions.pair_window_before'))
                    ->required()
                    ->numeric(),
                TextInput::make('pair_window_after_minutes')
                    ->label(__('panel.resources.rule_versions.pair_window_after'))
                    ->required()
                    ->numeric(),
                TextInput::make('min_scan_gap_seconds')
                    ->label(__('panel.resources.rule_versions.debounce'))
                    ->required()
                    ->numeric(),
                TextInput::make('min_session_minutes')
                    ->label(__('panel.resources.rule_versions.min_session'))
                    ->required()
                    ->numeric(),
                TextInput::make('hours_per_period')
                    ->label(__('panel.resources.rule_versions.hours_per_period'))
                    ->required()
                    ->numeric()
                    ->default(1.0),
                DatePicker::make('valid_from')
                    ->label(__('panel.common.valid_from'))
                    ->required()
                    ->helperText(__('panel.resources.rule_versions.valid_from_help')),
                Textarea::make('note')
                    ->label(__('panel.common.note'))
                    ->required()
                    ->helperText(__('panel.resources.rule_versions.note_help'))
                    ->columnSpanFull(),
            ]);
    }
}

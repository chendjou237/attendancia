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
                    ->required()
                    ->numeric(),
                TextInput::make('grace_early_minutes')
                    ->required()
                    ->numeric(),
                TextInput::make('pair_window_before_minutes')
                    ->required()
                    ->numeric(),
                TextInput::make('pair_window_after_minutes')
                    ->required()
                    ->numeric(),
                TextInput::make('min_scan_gap_seconds')
                    ->required()
                    ->numeric(),
                TextInput::make('min_session_minutes')
                    ->required()
                    ->numeric(),
                TextInput::make('hours_per_period')
                    ->required()
                    ->numeric()
                    ->default(1.0),
                DatePicker::make('valid_from')
                    ->required()
                    ->helperText('Applies to this date onward. Existing computed results before this date are never rewritten.'),
                Textarea::make('note')
                    ->required()
                    ->helperText('Why this version exists — leadership will ask, and future-you will not remember.')
                    ->columnSpanFull(),
            ]);
    }
}

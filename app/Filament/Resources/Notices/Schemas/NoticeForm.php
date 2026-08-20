<?php

namespace App\Filament\Resources\Notices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class NoticeForm
{
    // §13's open question on what a "notice" formally is hasn't been
    // answered; this is a reasonable starting set the officer can extend.
    // Not an enum like everything else in this app — a plain PHP array,
    // so its display labels are translated directly here rather than via
    // an enum's ->getLabel(), see 'resources.notices.types' in panel.php.
    public static function types(): array
    {
        return [
            'sick_leave' => __('panel.resources.notices.types.sick_leave'),
            'official_mission' => __('panel.resources.notices.types.official_mission'),
            'bereavement' => __('panel.resources.notices.types.bereavement'),
            'other' => __('panel.resources.notices.types.other'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('teacher_id')
                    ->label(__('panel.common.teacher'))
                    ->relationship('teacher', 'full_name')
                    ->searchable()
                    ->required(),
                Select::make('type')
                    ->label(__('panel.resources.notices.type'))
                    ->options(self::types())
                    ->required(),
                TextInput::make('reference')
                    ->label(__('panel.resources.notices.reference'))
                    ->helperText(__('panel.resources.notices.reference_help')),
                Textarea::make('note')
                    ->label(__('panel.common.note'))
                    ->columnSpanFull(),
                TextInput::make('file_path')
                    ->label(__('panel.resources.notices.attachment_path'))
                    ->helperText(__('panel.resources.notices.attachment_path_help')),
            ]);
    }
}

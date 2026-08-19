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
    private const TYPES = [
        'sick_leave' => 'Sick leave',
        'official_mission' => 'Official mission',
        'bereavement' => 'Bereavement',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('teacher_id')
                    ->relationship('teacher', 'full_name')
                    ->searchable()
                    ->required(),
                Select::make('type')
                    ->options(self::TYPES)
                    ->required(),
                TextInput::make('reference')
                    ->helperText('Paper reference the officer is recording, e.g. a doctor\'s note number.'),
                Textarea::make('note')
                    ->columnSpanFull(),
                TextInput::make('file_path')
                    ->label('Attachment path')
                    ->helperText('No upload handling yet — a path or reference to a scanned copy, if one exists.'),
            ]);
    }
}

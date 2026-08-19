<?php

namespace App\Filament\Resources\CalendarDays;

use App\Filament\Resources\CalendarDays\Pages\CreateCalendarDay;
use App\Filament\Resources\CalendarDays\Pages\EditCalendarDay;
use App\Filament\Resources\CalendarDays\Pages\ListCalendarDays;
use App\Filament\Resources\CalendarDays\Schemas\CalendarDayForm;
use App\Filament\Resources\CalendarDays\Tables\CalendarDaysTable;
use App\Models\CalendarDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CalendarDayResource extends Resource
{
    protected static ?string $model = CalendarDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CalendarDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalendarDaysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarDays::route('/'),
            'create' => CreateCalendarDay::route('/create'),
            'edit' => EditCalendarDay::route('/{record}/edit'),
        ];
    }
}

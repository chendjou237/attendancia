<?php

namespace App\Filament\Resources\Teachers\Tables;

use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\Teacher;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeachersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('staff_no')
                    ->label(__('panel.common.staff_no'))
                    ->searchable(),
                TextColumn::make('full_name')
                    ->label(__('panel.common.full_name'))
                    ->searchable(),
                TextColumn::make('employment_type')
                    ->label(__('panel.common.employment_type'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('active_from')
                    ->label(__('panel.resources.teachers.active_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('active_to')
                    ->label(__('panel.resources.teachers.active_to'))
                    ->date()
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
                Action::make('timetable')
                    ->label(__('panel.resources.teachers.timetable_action'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Teacher $record) => TeacherResource::getUrl('timetable', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

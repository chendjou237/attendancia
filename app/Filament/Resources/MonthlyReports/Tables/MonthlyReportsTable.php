<?php

namespace App\Filament\Resources\MonthlyReports\Tables;

use App\Enums\ReportState;
use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MonthlyReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('month', 'desc')
            ->columns([
                TextColumn::make('month')
                    ->label(__('panel.resources.monthly_reports.month'))
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('panel.common.status'))
                    ->badge()
                    ->color(fn (ReportState $state) => match ($state) {
                        ReportState::Draft => 'gray',
                        ReportState::OfficerReviewed => 'info',
                        ReportState::PrincipalApproved => 'warning',
                        ReportState::SentToHr => 'success',
                    }),
                TextColumn::make('teachers_count')
                    ->label(__('panel.common.teacher'))
                    // ->state(), not make('snapshot_json.teachers') — the
                    // latter resolves to an array (one entry per teacher),
                    // and TextColumn treats any array state as a *list* to
                    // render one item per element, calling formatStateUsing
                    // per teacher rather than on the array as a whole.
                    // ->state() replaces state resolution entirely instead
                    // of just formatting whatever it resolved to.
                    ->state(fn ($record) => count($record->snapshot_json['teachers'] ?? [])),
                TextColumn::make('pending_count')
                    ->label(__('panel.resources.monthly_reports.pending_exceptions'))
                    ->badge()
                    ->state(fn ($record) => $record->snapshot_json['totals']['pending'] ?? 0)
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('generated_at')
                    ->label(__('panel.resources.monthly_reports.generated_at'))
                    ->dateTime()
                    ->placeholder(__('panel.resources.monthly_reports.not_generated'))
                    ->sortable(),
                TextColumn::make('approvedBy.name')
                    ->label(__('panel.common.approved_by'))
                    ->placeholder(__('panel.common.dash_placeholder')),
                TextColumn::make('sent_to_hr_at')
                    ->label(__('panel.resources.monthly_reports.sent_to_hr'))
                    ->dateTime()
                    ->placeholder(__('panel.common.dash_placeholder')),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('panel.common.view'))
                    ->url(fn ($record) => MonthlyReportResource::getUrl('view', ['record' => $record])),
            ]);
    }
}

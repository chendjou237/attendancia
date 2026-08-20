<?php

namespace App\Filament\Resources\MonthlyReports\Pages;

use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use App\Services\Reporting\MonthlyReportGenerator;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMonthlyReports extends ListRecords
{
    protected static string $resource = MonthlyReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(__('panel.resources.monthly_reports.generate_action'))
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn () => auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false)
                ->schema([
                    DatePicker::make('month')
                        ->label(__('panel.resources.monthly_reports.month'))
                        ->required()
                        ->default(now()->startOfMonth()->subMonthNoOverflow())
                        ->displayFormat('F Y')
                        ->closeOnDateSelection()
                        ->helperText(__('panel.resources.monthly_reports.generate_help')),
                ])
                ->action(function (array $data, MonthlyReportGenerator $generator): void {
                    $report = $generator->generate(Carbon::parse($data['month']));

                    Notification::make()
                        ->title(__('panel.resources.monthly_reports.notification_ready', ['month' => $report->month->translatedFormat('F Y')]))
                        ->success()
                        ->send();
                }),
        ];
    }
}

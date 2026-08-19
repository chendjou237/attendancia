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
                ->label('Generate report')
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn () => auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false)
                ->schema([
                    DatePicker::make('month')
                        ->required()
                        ->default(now()->startOfMonth()->subMonthNoOverflow())
                        ->displayFormat('F Y')
                        ->closeOnDateSelection()
                        ->helperText('Any day within the target month — only the month matters. Generating an existing Draft or Officer-reviewed report refreshes it from the latest data; an already-approved report is untouched.'),
                ])
                ->action(function (array $data, MonthlyReportGenerator $generator): void {
                    $report = $generator->generate(Carbon::parse($data['month']));

                    Notification::make()
                        ->title("Report for {$report->month->format('F Y')} ready")
                        ->success()
                        ->send();
                }),
        ];
    }
}

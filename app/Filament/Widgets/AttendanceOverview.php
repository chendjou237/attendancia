<?php

namespace App\Filament\Widgets;

use App\Enums\PeriodStatus;
use App\Enums\ReportState;
use App\Filament\Pages\ExceptionQueue;
use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use App\Models\Device;
use App\Models\MonthlyReport;
use App\Models\PeriodResult;
use App\Services\Reporting\MonthlyReportGenerator;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AttendanceOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $pending = PeriodResult::query()
            ->current()
            ->whereIn('status', [PeriodStatus::Unpaired, PeriodStatus::LocationMismatch])
            ->whereNull('override_status')
            ->count();

        // A live snapshot for the current month, not a saved
        // monthly_reports row — nobody has asked to close this month
        // out yet, and "today" alone is too thin a slice for this
        // stat (empty on weekends, holidays, or before demo:seed has
        // run for the day) to be a useful headline number.
        $month = Carbon::today();
        $snapshot = app(MonthlyReportGenerator::class)->snapshot($month);

        $devicesActive = Device::where('is_active', true)->count();
        $devicesSilent = Device::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(2)))
            ->count();

        $currentReport = MonthlyReport::query()->whereDate('month', $month->copy()->startOfMonth()->toDateString())->first();
        $reportState = $currentReport?->state ?? null;

        return [
            Stat::make('Pending exceptions', $pending)
                ->description($pending > 0 ? 'Waiting in the exception queue' : 'Queue is clear')
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($pending > 0 ? 'danger' : 'success')
                ->url(ExceptionQueue::getUrl()),

            Stat::make('Present this month', $snapshot['totals']['present'] + $snapshot['totals']['present_admin'])
                ->description($month->format('F Y'))
                ->color('success'),

            Stat::make('Absent this month', $snapshot['totals']['absent'] + $snapshot['totals']['absent_justified'])
                ->description($month->format('F Y'))
                ->color(($snapshot['totals']['absent'] + $snapshot['totals']['absent_justified']) > 0 ? 'warning' : 'success'),

            Stat::make('Hours logged this month', $snapshot['totals']['payable_hours'] + $snapshot['totals']['oversight_hours'])
                ->description($snapshot['totals']['payable_hours'].' payable · '.$snapshot['totals']['oversight_hours'].' oversight')
                ->color('info'),

            Stat::make('Devices reporting', ($devicesActive - $devicesSilent).' / '.$devicesActive)
                ->description($devicesSilent > 0 ? 'One or more silent for 2h+' : 'All active devices seen recently')
                ->descriptionIcon($devicesSilent > 0 ? Heroicon::OutlinedSignalSlash : Heroicon::OutlinedSignal)
                ->color($devicesSilent > 0 ? 'warning' : 'success'),

            Stat::make('This month\'s report', $reportState?->getLabel() ?? 'Not generated')
                ->description('Click to open Monthly Reports')
                ->descriptionIcon(Heroicon::OutlinedDocumentChartBar)
                ->color($reportState === ReportState::SentToHr ? 'success' : 'gray')
                ->url(MonthlyReportResource::getUrl()),
        ];
    }
}

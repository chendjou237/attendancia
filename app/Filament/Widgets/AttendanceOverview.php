<?php

namespace App\Filament\Widgets;

use App\Enums\PeriodStatus;
use App\Filament\Pages\ExceptionQueue;
use App\Models\Device;
use App\Models\PeriodResult;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AttendanceOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = Carbon::today();

        $pending = PeriodResult::query()
            ->current()
            ->whereIn('status', [PeriodStatus::Unpaired, PeriodStatus::LocationMismatch])
            ->whereNull('override_status')
            ->count();

        // whereDate(), not where('date', $today): `date` is a `date` cast,
        // which serialises to "Y-m-d H:i:s" on save — a plain string
        // comparison can silently miss same-day rows on a DB that stores
        // dates as text (see PeriodResult::casts(), same trap documented
        // throughout the pairing/rule engine).
        $todayResults = PeriodResult::query()
            ->current()
            ->whereDate('date', $today)
            ->get(['status', 'override_status']);

        $present = $todayResults->filter(fn (PeriodResult $r) => $r->effectiveStatus()->countsAsTaught())->count();
        $absent = $todayResults->filter(fn (PeriodResult $r) => in_array($r->effectiveStatus(), [PeriodStatus::Absent, PeriodStatus::AbsentJustified], true))->count();

        $devicesActive = Device::where('is_active', true)->count();
        $devicesSilent = Device::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(2)))
            ->count();

        return [
            Stat::make('Pending exceptions', $pending)
                ->description($pending > 0 ? 'Waiting in the exception queue' : 'Queue is clear')
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($pending > 0 ? 'danger' : 'success')
                ->url(ExceptionQueue::getUrl()),

            Stat::make('Present today', $present)
                ->description($today->toFormattedDateString())
                ->color('success'),

            Stat::make('Absent today', $absent)
                ->description($today->toFormattedDateString())
                ->color($absent > 0 ? 'warning' : 'success'),

            Stat::make('Devices reporting', ($devicesActive - $devicesSilent).' / '.$devicesActive)
                ->description($devicesSilent > 0 ? 'One or more silent for 2h+' : 'All active devices seen recently')
                ->descriptionIcon($devicesSilent > 0 ? Heroicon::OutlinedSignalSlash : Heroicon::OutlinedSignal)
                ->color($devicesSilent > 0 ? 'warning' : 'success'),
        ];
    }
}

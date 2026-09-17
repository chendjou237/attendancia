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
    /**
     * Filament's default is 5s (Filament\Widgets\Concerns\CanPoll).
     * These are month-to-date operational figures that move a handful of
     * times a day — refreshing them twelve times a minute cost far more
     * than it told anyone, on a server that is also running MySQL and
     * the Hikvision stream worker.
     */
    protected ?string $pollingInterval = '60s';

    /**
     * How old the newest period_result may be before the dashboard calls
     * the computation stale. The in-day schedule runs every ten minutes,
     * so thirty is three missed passes — late enough not to flicker on a
     * slow run, early enough to catch a dead scheduler the same morning.
     */
    private const STALE_COMPUTE_MINUTES = 30;

    /**
     * These stats are live, in-progress numbers — pending exceptions,
     * this month's running present/absent/hours before any report has
     * been approved. HR's whole job is reading the *frozen*, approved
     * Monthly Reports instead (docs/onboarding.md §4: "there's
     * deliberately no way to pull an unfinished month's numbers" from
     * anywhere but that screen), so showing this operational snapshot
     * to HR — including a Pending Exceptions stat linking to a page
     * they're not authorized to open — would contradict that.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer', 'principal']) ?? false;
    }

    protected function getStats(): array
    {
        $pending = PeriodResult::query()
            ->current()
            ->whereIn('status', [PeriodStatus::Unpaired, PeriodStatus::LocationMismatch])
            ->whereNull('override_status')
            ->count();

        // Live current-month figures, not a saved monthly_reports row —
        // nobody has asked to close this month out yet, and "today"
        // alone is too thin a slice for this stat (empty on weekends,
        // holidays, or before demo:seed has run for the day) to be a
        // useful headline number.
        //
        // totals(), not snapshot(): only the totals are rendered here,
        // and snapshot() would hydrate every period_result for the month
        // with its teacher and rule version to produce them.
        $month = Carbon::today();
        $totals = app(MonthlyReportGenerator::class)->totals($month);

        $devicesActive = Device::where('is_active', true)->count();
        $devicesSilent = Device::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(2)))
            ->count();

        $currentReport = MonthlyReport::query()->where('month', $month->copy()->startOfMonth()->toDateString())->first();
        $reportState = $currentReport?->state ?? null;

        // Attendance is only as current as the last attendance:compute
        // run, and nothing on any screen used to say when that was. A
        // stopped scheduler is otherwise indistinguishable from a day on
        // which nobody scanned: the queue simply fills with "no scan-in"
        // while staff watch teachers tap in front of them. Stale here
        // means the in-day schedule (every ten minutes, routes/console
        // .php) is not running.
        $lastComputedAt = PeriodResult::max('computed_at');
        $lastComputedAt = $lastComputedAt !== null ? Carbon::parse($lastComputedAt) : null;
        $computeIsStale = $lastComputedAt === null || $lastComputedAt->lessThan(now()->subMinutes(self::STALE_COMPUTE_MINUTES));

        return [
            Stat::make(__('panel.widgets.attendance_overview.pending_exceptions'), $pending)
                ->description($pending > 0
                    ? __('panel.widgets.attendance_overview.pending_exceptions_waiting')
                    : __('panel.widgets.attendance_overview.pending_exceptions_clear'))
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($pending > 0 ? 'danger' : 'success')
                ->url(ExceptionQueue::getUrl()),

            Stat::make(__('panel.widgets.attendance_overview.present_this_month'), $totals['present'] + $totals['present_admin'])
                ->description($month->translatedFormat('F Y'))
                ->color('success'),

            Stat::make(__('panel.widgets.attendance_overview.absent_this_month'), $totals['absent'] + $totals['absent_justified'])
                ->description($month->translatedFormat('F Y'))
                ->color(($totals['absent'] + $totals['absent_justified']) > 0 ? 'warning' : 'success'),

            Stat::make(__('panel.widgets.attendance_overview.hours_logged_this_month'), $totals['payable_hours'] + $totals['oversight_hours'])
                ->description(__('panel.widgets.attendance_overview.hours_breakdown', [
                    'payable' => $totals['payable_hours'],
                    'oversight' => $totals['oversight_hours'],
                ]))
                ->color('info'),

            Stat::make(__('panel.widgets.attendance_overview.devices_reporting'), ($devicesActive - $devicesSilent).' / '.$devicesActive)
                ->description($devicesSilent > 0
                    ? __('panel.widgets.attendance_overview.devices_silent')
                    : __('panel.widgets.attendance_overview.devices_all_active'))
                ->descriptionIcon($devicesSilent > 0 ? Heroicon::OutlinedSignalSlash : Heroicon::OutlinedSignal)
                ->color($devicesSilent > 0 ? 'warning' : 'success'),

            Stat::make(__('panel.widgets.attendance_overview.current_report'), $reportState?->getLabel() ?? __('panel.widgets.attendance_overview.current_report_not_generated'))
                ->description(__('panel.widgets.attendance_overview.current_report_description'))
                ->descriptionIcon(Heroicon::OutlinedDocumentChartBar)
                ->color($reportState === ReportState::SentToHr ? 'success' : 'gray')
                ->url(MonthlyReportResource::getUrl()),

            Stat::make(
                __('panel.widgets.attendance_overview.last_computed'),
                $lastComputedAt?->diffForHumans() ?? __('panel.widgets.attendance_overview.last_computed_never'),
            )
                ->description($computeIsStale
                    ? __('panel.widgets.attendance_overview.last_computed_stale', ['minutes' => self::STALE_COMPUTE_MINUTES])
                    : __('panel.widgets.attendance_overview.last_computed_fresh'))
                ->descriptionIcon($computeIsStale ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedArrowPath)
                ->color($computeIsStale ? 'danger' : 'success'),
        ];
    }
}

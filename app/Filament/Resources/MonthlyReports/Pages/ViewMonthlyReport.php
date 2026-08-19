<?php

namespace App\Filament\Resources\MonthlyReports\Pages;

use App\Enums\ReportState;
use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use App\Models\AuditLog;
use App\Services\Reporting\MonthlyReportGenerator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * §10: "per-teacher headline with per-period detail underneath." The
 * breakdown table is read from the frozen (or last-generated)
 * snapshot_json, never recomputed live on view — what's on screen here
 * is always exactly what a Regenerate/Approve action last wrote, which
 * is the point of freezing it in the first place.
 */
class ViewMonthlyReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MonthlyReportResource::class;

    protected string $view = 'filament.resources.monthly-reports.pages.view-monthly-report';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Monthly report — '.$this->record->month->format('F Y');
    }

    protected function getHeaderActions(): array
    {
        $state = $this->record->state;

        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible($this->record->snapshot_json !== null)
                ->url(fn () => route('monthly-reports.pdf', ['report' => $this->record]))
                ->openUrlInNewTab(),

            Action::make('regenerate')
                ->label('Regenerate from latest data')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(in_array($state, [ReportState::Draft, ReportState::OfficerReviewed], true))
                ->action(function (MonthlyReportGenerator $generator): void {
                    $generator->generate($this->record->month);
                    $this->record->refresh();

                    Notification::make()->title('Report refreshed from current data')->success()->send();
                }),

            Action::make('markReviewed')
                ->label('Mark reviewed')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('info')
                ->requiresConfirmation()
                ->visible($state === ReportState::Draft)
                ->action(function (): void {
                    $this->transitionTo(ReportState::OfficerReviewed, 'officer_reviewed');
                }),

            Action::make('approve')
                ->label('Principal approve')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This freezes the report. Once approved, it can no longer be regenerated — a correction after this point needs a manual, audited adjustment, not a re-run of the generator.')
                ->visible(fn () => $state === ReportState::OfficerReviewed && auth()->user()?->hasAnyRole(['principal', 'admin']))
                ->action(function (): void {
                    $this->record->update(['approved_by' => auth()->id(), 'approved_at' => now()]);
                    $this->transitionTo(ReportState::PrincipalApproved, 'principal_approved');
                }),

            Action::make('sendToHr')
                ->label('Send to HR')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->requiresConfirmation()
                ->visible($state === ReportState::PrincipalApproved)
                ->action(function (): void {
                    $this->record->update(['sent_to_hr_at' => now()]);
                    $this->transitionTo(ReportState::SentToHr, 'sent_to_hr');
                }),
        ];
    }

    private function transitionTo(ReportState $to, string $action): void
    {
        $before = $this->record->only(['state']);
        $this->record->update(['state' => $to]);
        $this->record->refresh();

        AuditLog::record(
            entity: 'monthly_reports',
            entityId: $this->record->id,
            action: $action,
            before: $before,
            after: $this->record->only(['state']),
        );

        Notification::make()->title('Report state updated')->success()->send();
    }
}

<?php

namespace App\Filament\Resources\MonthlyReports\Pages;

use App\Enums\ReportState;
use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use App\Http\Controllers\MonthlyReportPdfController;
use App\Models\AuditLog;
use App\Services\Reporting\MonthlyReportGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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

    private const PDF_COLUMNS_SESSION_KEY = 'monthly_report_pdf_columns';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return __('panel.resources.monthly_reports.view_title', ['month' => $this->record->month->translatedFormat('F Y')]);
    }

    protected function getHeaderActions(): array
    {
        $state = $this->record->state;

        return [
            // Pick the columns to print; the last choice is kept for the
            // session so a regular print layout isn't re-ticked every month.
            Action::make('downloadPdf')
                ->label(__('panel.common.download_pdf'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible($this->record->snapshot_json !== null)
                ->modalHeading(__('panel.resources.monthly_reports.pdf_columns_heading'))
                ->modalDescription(__('panel.resources.monthly_reports.pdf_columns_help'))
                ->modalSubmitActionLabel(__('panel.common.download_pdf'))
                ->schema([
                    CheckboxList::make('columns')
                        ->hiddenLabel()
                        ->options(MonthlyReportPdfController::columns())
                        ->default(fn (): array => MonthlyReportPdfController::selectedColumns(
                            implode(',', (array) session(self::PDF_COLUMNS_SESSION_KEY, [])),
                        ))
                        ->columns(3)
                        ->bulkToggleable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $columns = MonthlyReportPdfController::selectedColumns(implode(',', $data['columns']));
                    session([self::PDF_COLUMNS_SESSION_KEY => $columns]);
                    $pdf = MonthlyReportPdfController::makePdf($this->record, $columns);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        MonthlyReportPdfController::filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            Action::make('regenerate')
                ->label(__('panel.resources.monthly_reports.regenerate_action'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(in_array($state, [ReportState::Draft, ReportState::OfficerReviewed], true))
                ->action(function (MonthlyReportGenerator $generator): void {
                    $generator->generate($this->record->month);
                    $this->record->refresh();

                    Notification::make()->title(__('panel.resources.monthly_reports.notification_refreshed'))->success()->send();
                }),

            Action::make('markReviewed')
                ->label(__('panel.resources.monthly_reports.mark_reviewed_action'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('info')
                ->requiresConfirmation()
                ->visible($state === ReportState::Draft)
                ->action(function (): void {
                    $this->transitionTo(ReportState::OfficerReviewed, 'officer_reviewed');
                }),

            Action::make('approve')
                ->label(__('panel.resources.monthly_reports.approve_action'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('panel.resources.monthly_reports.approve_modal_description'))
                ->visible(fn () => $state === ReportState::OfficerReviewed && auth()->user()?->hasAnyRole(['principal', 'admin']))
                ->action(function (): void {
                    $this->record->update(['approved_by' => auth()->id(), 'approved_at' => now()]);
                    $this->transitionTo(ReportState::PrincipalApproved, 'principal_approved');
                }),

            Action::make('sendToHr')
                ->label(__('panel.resources.monthly_reports.send_to_hr_action'))
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

        Notification::make()->title(__('panel.resources.monthly_reports.notification_state_updated'))->success()->send();
    }
}

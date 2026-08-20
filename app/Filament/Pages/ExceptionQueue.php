<?php

namespace App\Filament\Pages;

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Models\AuditLog;
use App\Models\PeriodResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * §10: "The exception queue is a deliverable, not a nice-to-have."
 *
 * §9: UNPAIRED and LOCATION_MISMATCH are "pending" statuses, not a
 * judgement — this page is where they get resolved by a human, never
 * silently. Scoped to Phase A/B data (period_results, sessions); the
 * plan's "unmatched biometric scans" and "orphan scans" items are
 * raw_events-shaped and slot in once Phase C's ingestion exists,
 * without needing to rearchitect this page — see the Phase C section
 * at the bottom of this file for the extension point.
 */
class ExceptionQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected string $view = 'filament.pages.exception-queue';

    /**
     * Onboarding §2/§3: Officer resolves the queue day to day, Principal
     * reads along and can override; HR has no reason to be here.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer', 'principal']) ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PeriodResult::query()
                    ->current()
                    ->whereIn('status', [PeriodStatus::Unpaired, PeriodStatus::LocationMismatch])
                    ->whereNull('override_status')
            )
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('teacher.full_name')->label('Teacher')->searchable(),
                TextColumn::make('slot.seq')->label('Period'),
                TextColumn::make('classCode.code')->label('Class'),
                TextColumn::make('status')->badge()->color('danger'),
                TextColumn::make('session.anomaly_code')
                    ->label('Why')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => filled($state)
                        ? (SessionAnomaly::tryFrom($state)?->getLabel() ?? $state)
                        : null),
            ])
            ->recordActions([
                Action::make('override')
                    ->label('Override')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Select::make('override_status')
                            ->label('Actual status')
                            ->options([
                                PeriodStatus::Present->value => PeriodStatus::Present->getLabel(),
                                PeriodStatus::Absent->value => PeriodStatus::Absent->getLabel(),
                                PeriodStatus::AbsentJustified->value => PeriodStatus::AbsentJustified->getLabel(),
                            ])
                            ->required(),
                        Textarea::make('override_reason')
                            ->label('Reason')
                            ->required()
                            ->helperText('Mandatory — this becomes part of the audit trail (§9, §14).'),
                    ])
                    ->action(function (PeriodResult $record, array $data): void {
                        $before = $record->only(['status', 'override_status', 'override_reason']);

                        $record->update([
                            'override_status' => $data['override_status'],
                            'override_reason' => $data['override_reason'],
                            'override_by' => auth()->id(),
                            'override_at' => now(),
                        ]);

                        AuditLog::record(
                            entity: 'period_results',
                            entityId: $record->id,
                            action: 'override',
                            before: $before,
                            after: $record->only(['status', 'override_status', 'override_reason']),
                        );

                        Notification::make()->title('Override recorded')->success()->send();
                    }),
            ]);
    }
}

// --- Phase C extension point ---
// Once raw_events ingestion exists: a second table (or a tab on this
// page) for events with teacher_id null (unmatched biometric scans —
// employeeNoString never resolved to a teacher) and for events that
// resolved to a teacher but fall inside no session's pairing window at
// all (orphan scans — the teacher scanned, but not for any expected
// lesson). Both are diagnostic, not overridable the way a period
// result is; they inform the officer rather than getting resolved.

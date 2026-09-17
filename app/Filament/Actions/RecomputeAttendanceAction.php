<?php

namespace App\Filament\Actions;

use App\Models\AuditLog;
use App\Models\Teacher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-runs attendance:compute for one date from inside the panel.
 *
 * Exists because the gap between "a teacher scanned" and "the panel
 * knows about it" is the scheduler, and the scheduler is invisible to
 * the people who answer for the numbers. When a teacher is standing at
 * the office saying they scanned, staff need to refresh that day
 * themselves rather than wait for the next scheduled pass or ask for a
 * command line on the server.
 *
 * Shared by ExceptionQueue and TeacherAttendance rather than written
 * twice: both screens read the same period_results and both are where
 * the question gets asked.
 *
 * Recomputing is not an override — it re-derives from raw_events under
 * the same rules, and PeriodResultWriter leaves override_status
 * untouched (a human decision survives any recomputation). It is still
 * audited: it rewrites computed records that payroll reads, so §14
 * wants an actor against it.
 */
class RecomputeAttendanceAction
{
    public static function make(): Action
    {
        return Action::make('recomputeAttendance')
            ->label(__('panel.actions.recompute.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalDescription(__('panel.actions.recompute.description'))
            ->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false)
            ->schema([
                DatePicker::make('date')
                    ->label(__('panel.common.date'))
                    ->default(now(config('attendance.timezone'))->toDateString())
                    ->native(false)
                    ->required(),
                Select::make('teacher_id')
                    ->label(__('panel.common.teacher'))
                    ->placeholder(__('panel.actions.recompute.all_teachers'))
                    ->options(fn (): array => Teacher::query()->orderBy('full_name')->pluck('full_name', 'id')->all())
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                // Carbon::parse(...)->toDateString(): the DatePicker's
                // state comes back as a full datetime string even though
                // only a date was entered — the same normalisation
                // TeacherAttendance's date filter needs for the same
                // reason.
                $date = Carbon::parse($data['date'])->toDateString();
                $teacher = filled($data['teacher_id'] ?? null) ? Teacher::find($data['teacher_id']) : null;

                Artisan::call('attendance:compute', array_filter([
                    'date' => $date,
                    '--teacher' => $teacher !== null ? [$teacher->staff_no] : null,
                ]));

                AuditLog::record(
                    entity: 'period_results',
                    entityId: $teacher?->id ?? 0,
                    action: 'recompute',
                    before: null,
                    after: ['date' => $date, 'teacher' => $teacher?->staff_no],
                );

                Notification::make()
                    ->title(__('panel.actions.recompute.notification', [
                        'date' => $date,
                        'scope' => $teacher?->full_name ?? __('panel.actions.recompute.all_teachers'),
                    ]))
                    ->success()
                    ->send();
            });
    }
}

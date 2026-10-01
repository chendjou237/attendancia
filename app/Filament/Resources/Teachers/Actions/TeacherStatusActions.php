<?php

namespace App\Filament\Resources\Teachers\Actions;

use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\Teacher;
use App\Services\Attendance\TeacherDeactivator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Deactivate / merge-as-duplicate / reactivate, shared by the Teachers
 * table and the edit page. All three go through TeacherDeactivator, which
 * recomputes what they change — editing active_to on the form does not.
 */
class TeacherStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('panel.resources.teachers.deactivate_action'))
            ->icon(Heroicon::OutlinedPause)
            ->color('gray')
            ->visible(fn (Teacher $record): bool => TeacherResource::canEdit($record)
                && $record->merged_into_id === null
                && $record->active_to === null)
            ->modalDescription(__('panel.resources.teachers.deactivate_help'))
            ->schema([
                DatePicker::make('last_day')
                    ->label(__('panel.resources.teachers.deactivate_last_day'))
                    ->default(now(config('attendance.timezone'))->toDateString())
                    ->minDate(fn (Teacher $record) => $record->active_from)
                    ->native(false)
                    ->required(),
            ])
            ->action(self::run(fn (TeacherDeactivator $service, Teacher $record, array $data): string => __(
                'panel.resources.teachers.deactivate_done',
                ['name' => $record->full_name, 'count' => $service->deactivate($record, Carbon::parse($data['last_day'])->startOfDay())],
            )));
    }

    public static function merge(): Action
    {
        return Action::make('mergeDuplicate')
            ->label(__('panel.resources.teachers.merge_action'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->visible(fn (Teacher $record): bool => TeacherResource::canEdit($record) && $record->merged_into_id === null)
            ->modalDescription(__('panel.resources.teachers.merge_help'))
            ->fillForm(fn (Teacher $record): array => ['keeper_id' => self::likelyKeeper($record)?->id])
            ->schema([
                Select::make('keeper_id')
                    ->label(__('panel.resources.teachers.merge_keeper'))
                    ->options(fn (Teacher $record): array => Teacher::query()
                        ->whereKeyNot($record->id)
                        ->whereNull('merged_into_id')
                        ->orderBy('full_name')
                        ->get()
                        ->mapWithKeys(fn (Teacher $t) => [$t->id => "{$t->full_name} — {$t->staff_no}"])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(self::run(function (TeacherDeactivator $service, Teacher $record, array $data): string {
                $keeper = Teacher::findOrFail($data['keeper_id']);

                return __('panel.resources.teachers.merge_done', [
                    'name' => $record->full_name,
                    'keeper' => $keeper->full_name,
                    'count' => $service->mergeInto($record, $keeper),
                ]);
            }));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('panel.resources.teachers.reactivate_action'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (Teacher $record): bool => TeacherResource::canEdit($record)
                && ($record->merged_into_id !== null || $record->active_to !== null))
            ->requiresConfirmation()
            ->modalDescription(__('panel.resources.teachers.reactivate_help'))
            ->action(self::run(fn (TeacherDeactivator $service, Teacher $record): string => __(
                'panel.resources.teachers.reactivate_done',
                ['name' => $record->full_name, 'count' => $service->reactivate($record)],
            )));
    }

    /**
     * Pre-selects the obvious keeper: another record with the same name,
     * preferring the one that currently holds a fingerprint (that is where
     * new scans land).
     */
    private static function likelyKeeper(Teacher $record): ?Teacher
    {
        return Teacher::query()
            ->whereKeyNot($record->id)
            ->whereNull('merged_into_id')
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [mb_strtolower(trim($record->full_name))])
            ->withCount(['biometricIds as open_biometric_ids' => fn ($q) => $q->whereNull('valid_to')])
            ->orderByDesc('open_biometric_ids')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  Closure(TeacherDeactivator, Teacher, array): string  $operation  returns the success message
     */
    private static function run(Closure $operation): Closure
    {
        return function (Action $action, Teacher $record, array $data) use ($operation): void {
            try {
                $message = $operation(app(TeacherDeactivator::class), $record, $data);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
                $action->halt();

                return;
            }

            Notification::make()->title($message)->success()->send();
        };
    }
}

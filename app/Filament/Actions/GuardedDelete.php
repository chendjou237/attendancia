<?php

namespace App\Filament\Actions;

use App\Models\Contracts\GuardsDeletion;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Delete actions that ask the record first (App\Models\Contracts\GuardsDeletion).
 *
 * Exists because Filament's stock delete trusts the database: a teacher with
 * recorded attendance hit the period_results RESTRICT key and surfaced as a
 * bare error, and a bulk delete on Users removed every login — the admin's
 * own included — because nothing references users with RESTRICT.
 *
 * The check has to happen here rather than in a model `deleting` event:
 * Spatie's HasRoles detaches a user's roles in its own `deleting` listener,
 * which runs before any listener the model registers, so a refusal there
 * would still strip the roles.
 */
class GuardedDelete
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, Model $record): void {
                $reason = $record instanceof GuardsDeletion ? $record->deletionBlockedReason() : null;

                if ($reason === null) {
                    return;
                }

                Notification::make()
                    ->title(__('panel.actions.delete_refused'))
                    ->body($reason)
                    ->danger()
                    ->send();

                $action->cancel();
            });
    }

    /**
     * Records are checked one at a time, as each is reached — so "the last
     * admin" is judged against what is left, not against the selection.
     */
    public static function bulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, iterable $records): void {
                $isFirstException = true;

                foreach ($records as $record) {
                    $reason = $record instanceof GuardsDeletion ? $record->deletionBlockedReason() : null;

                    if ($reason !== null) {
                        $action->reportBulkProcessingFailure($reason, $reason);

                        continue;
                    }

                    try {
                        $record->delete() || $action->reportBulkProcessingFailure();
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure();

                        if ($isFirstException) {
                            report($exception);
                            $isFirstException = false;
                        }
                    }
                }
            });
    }
}

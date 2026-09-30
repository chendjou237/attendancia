<?php

namespace App\Models\Contracts;

/**
 * A model that can refuse to be deleted from the panel, with a reason a
 * person can act on. Checked by App\Filament\Actions\GuardedDelete before
 * anything is touched.
 */
interface GuardsDeletion
{
    /**
     * Why this record may not be deleted right now (already translated),
     * or null if it may.
     */
    public function deletionBlockedReason(): ?string;
}

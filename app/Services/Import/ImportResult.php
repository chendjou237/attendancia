<?php

namespace App\Services\Import;

/**
 * All-or-nothing: every importer validates every row first and only
 * writes anything if there are zero errors. A partially-applied CSV
 * import — half the teachers created, the rest rejected — is a worse,
 * more confusing state for a non-technical user to recover from than
 * "fix these 3 rows and re-upload the whole file."
 */
final class ImportResult
{
    /**
     * @param  array<int, ImportError>  $errors
     */
    public function __construct(
        public readonly int $rowsRead,
        public readonly int $created,
        public readonly int $updated,
        public readonly array $errors,
    ) {}

    public function successful(): bool
    {
        return $this->errors === [];
    }
}

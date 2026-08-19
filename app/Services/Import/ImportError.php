<?php

namespace App\Services\Import;

/**
 * $row is 1-indexed against the file as a human would open it in a
 * spreadsheet (header = row 1, first data row = row 2), not the
 * 0-indexed array position — the whole point is a person can find the
 * row without translating.
 */
final class ImportError
{
    public function __construct(
        public readonly int $row,
        public readonly string $message,
    ) {}

    public function __toString(): string
    {
        return "Row {$this->row}: {$this->message}";
    }
}

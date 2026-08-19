<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Native fgetcsv, not a package (§14: ask before adding a Composer
 * dependency) — a plain header-row CSV is all either importer needs.
 * Strips a UTF-8 BOM (Excel adds one when you "Save As CSV" on
 * Windows, which is the realistic path this data arrives by) and skips
 * fully-blank rows rather than erroring on the trailing blank line
 * most spreadsheet exports leave.
 */
class CsvReader
{
    /**
     * @return array<int, array<string, string>> 0-indexed; row 0 is the
     *                                            first row AFTER the
     *                                            header (file row 2)
     */
    public function readAssoc(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open file: {$path}");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === ['']) {
                continue; // blank trailing line
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($line[$i] ?? ''));
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}

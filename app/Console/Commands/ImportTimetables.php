<?php

namespace App\Console\Commands;

use App\Services\Import\TimetableImporter;
use Illuminate\Console\Command;

class ImportTimetables extends Command
{
    protected $signature = 'timetables:import {file : path to a CSV file} {--entered-by= : users.id to record as the entered_by on new versions}';

    protected $description = 'Bulk-create or replace timetable versions from a CSV file (staff_no, valid_from, day, period, class_code, room_code).';

    public function handle(TimetableImporter $importer): int
    {
        if (! is_file($this->argument('file'))) {
            $this->error("File not found: {$this->argument('file')}");

            return self::FAILURE;
        }

        $enteredBy = $this->option('entered-by') ? (int) $this->option('entered-by') : null;
        $result = $importer->import($this->argument('file'), $enteredBy);

        if (! $result->successful()) {
            $this->error("Import failed — {$result->rowsRead} row(s) read, ".count($result->errors).' error(s). Nothing was saved.');
            foreach ($result->errors as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $this->info("Imported successfully: {$result->created} version(s) touched, {$result->updated} entries written.");

        return self::SUCCESS;
    }
}

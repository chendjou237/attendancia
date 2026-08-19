<?php

namespace App\Console\Commands;

use App\Services\Import\TeacherImporter;
use Illuminate\Console\Command;

class ImportTeachers extends Command
{
    protected $signature = 'teachers:import {file : path to a CSV file}';

    protected $description = 'Bulk-create or update teachers from a CSV file (staff_no, full_name, employment_type, active_from, biometric_id).';

    public function handle(TeacherImporter $importer): int
    {
        if (! is_file($this->argument('file'))) {
            $this->error("File not found: {$this->argument('file')}");

            return self::FAILURE;
        }

        $result = $importer->import($this->argument('file'));

        if (! $result->successful()) {
            $this->error("Import failed — {$result->rowsRead} row(s) read, ".count($result->errors).' error(s). Nothing was saved.');
            foreach ($result->errors as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $this->info("Imported successfully: {$result->created} created, {$result->updated} updated.");

        return self::SUCCESS;
    }
}

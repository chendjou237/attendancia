<?php

namespace App\Services\Import;

use App\Enums\EmploymentType;
use App\Models\Teacher;
use App\Services\Attendance\TeacherBiometricIdAssigner;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CSV columns: staff_no, full_name, employment_type, active_from,
 * biometric_id (optional).
 *
 * employment_type is "hourly" or "salaried" (case-insensitive).
 * active_from is any date PHP can parse; Y-m-d is safest. Re-importing
 * an existing staff_no updates that teacher rather than erroring — the
 * realistic workflow is "fix the mistake, re-upload the whole file."
 */
class TeacherImporter
{
    public function __construct(
        private readonly CsvReader $reader,
        private readonly TeacherBiometricIdAssigner $assigner,
    ) {}

    public function import(string $filePath): ImportResult
    {
        $rows = $this->reader->readAssoc($filePath);
        $errors = [];
        $validated = [];
        $seenStaffNos = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $staffNo = $row['staff_no'] ?? '';

            if ($staffNo !== '' && isset($seenStaffNos[$staffNo])) {
                $errors[] = new ImportError($rowNum, "Duplicate staff_no \"{$staffNo}\" — also on row {$seenStaffNos[$staffNo]}.");

                continue;
            }

            $rowErrors = $this->validateRow($row, $rowNum);

            if ($rowErrors !== []) {
                $errors = [...$errors, ...$rowErrors];

                continue;
            }

            $seenStaffNos[$staffNo] = $rowNum;
            $validated[] = $row;
        }

        if ($errors !== []) {
            return new ImportResult(count($rows), 0, 0, $errors);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($validated, &$created, &$updated) {
            foreach ($validated as $row) {
                $employmentType = strtolower($row['employment_type']) === 'salaried'
                    ? EmploymentType::Salaried
                    : EmploymentType::Hourly;

                $teacher = Teacher::updateOrCreate(
                    ['staff_no' => $row['staff_no']],
                    [
                        'full_name' => $row['full_name'],
                        'employment_type' => $employmentType,
                        'active_from' => Carbon::parse($row['active_from'])->toDateString(),
                    ],
                );

                $teacher->wasRecentlyCreated ? $created++ : $updated++;

                if (filled($row['biometric_id'] ?? null)) {
                    $this->assigner->assign($teacher, $row['biometric_id'], Carbon::parse($row['active_from']));
                }
            }
        });

        return new ImportResult(count($rows), $created, $updated, []);
    }

    /**
     * @return array<int, ImportError>
     */
    private function validateRow(array $row, int $rowNum): array
    {
        $errors = [];

        if (blank($row['staff_no'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'staff_no is required.');
        }

        if (blank($row['full_name'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'full_name is required.');
        }

        $employmentType = strtolower(trim($row['employment_type'] ?? ''));
        if (! in_array($employmentType, ['hourly', 'salaried'], true)) {
            $errors[] = new ImportError($rowNum, 'employment_type must be "hourly" or "salaried", got "'.($row['employment_type'] ?? '').'".');
        }

        if (blank($row['active_from'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'active_from is required.');
        } else {
            try {
                Carbon::parse($row['active_from']);
            } catch (Throwable) {
                $errors[] = new ImportError($rowNum, "active_from \"{$row['active_from']}\" is not a recognisable date.");
            }
        }

        return $errors;
    }
}

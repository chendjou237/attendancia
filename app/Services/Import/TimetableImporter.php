<?php

namespace App\Services\Import;

use App\Enums\TimetableState;
use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CSV columns: staff_no, valid_from, day, period, class_code, room_code.
 *
 * One row per cell of the paper grid — day is a name (Monday..Saturday,
 * case-insensitive), period is the slot's seq number for that day. All
 * four lookups (teacher, slot, class code, room) are by human-readable
 * code, matching how the officer actually describes a grid, not by
 * internal database id.
 *
 * Import is a REPLACE per (staff_no, valid_from): every entry the file
 * doesn't mention for that teacher/version is removed, and everything
 * it does mention is created or updated to match — the file is treated
 * as the complete grid for that version, the same way a paper sheet
 * would be, and re-running the same file twice is a no-op.
 */
class TimetableImporter
{
    private const DAY_NAMES = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    public function __construct(
        private readonly CsvReader $reader,
    ) {}

    public function import(string $filePath, ?int $enteredBy = null): ImportResult
    {
        $rows = $this->reader->readAssoc($filePath);
        $errors = [];
        $validated = [];
        $seenCells = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $resolved = $this->validateAndResolveRow($row, $rowNum, $errors);

            if ($resolved === null) {
                continue;
            }

            $cellKey = $resolved['staff_no'].'|'.$resolved['valid_from'].'|'.$resolved['day_of_week'].'|'.$resolved['seq'];

            if (isset($seenCells[$cellKey])) {
                $errors[] = new ImportError($rowNum, "Duplicate entry for this teacher/day/period — also on row {$seenCells[$cellKey]}.");

                continue;
            }

            $seenCells[$cellKey] = $rowNum;
            $validated[] = $resolved;
        }

        if ($errors !== []) {
            return new ImportResult(count($rows), 0, 0, $errors);
        }

        [$versionsTouched, $entriesWritten] = $this->applyImport($validated, $enteredBy);

        return new ImportResult(count($rows), $versionsTouched, $entriesWritten, []);
    }

    /**
     * @return array{staff_no: string, valid_from: string, day_of_week: int, seq: int, class_code_id: int, room_id: int, slot_id: int}|null
     */
    private function validateAndResolveRow(array $row, int $rowNum, array &$errors): ?array
    {
        $before = count($errors);

        $teacher = null;
        if (blank($row['staff_no'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'staff_no is required.');
        } else {
            $teacher = Teacher::where('staff_no', $row['staff_no'])->first();
            if ($teacher === null) {
                $errors[] = new ImportError($rowNum, "Unknown staff_no \"{$row['staff_no']}\" — import teachers first.");
            }
        }

        $validFrom = null;
        if (blank($row['valid_from'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'valid_from is required.');
        } else {
            try {
                $validFrom = Carbon::parse($row['valid_from']);
            } catch (Throwable) {
                $errors[] = new ImportError($rowNum, "valid_from \"{$row['valid_from']}\" is not a recognisable date.");
            }
        }

        $dayOfWeek = null;
        $dayInput = strtolower(trim($row['day'] ?? ''));
        if ($dayInput === '') {
            $errors[] = new ImportError($rowNum, 'day is required.');
        } elseif (! isset(self::DAY_NAMES[$dayInput])) {
            $errors[] = new ImportError($rowNum, "day \"{$row['day']}\" is not a recognised day name (Monday..Saturday).");
        } else {
            $dayOfWeek = self::DAY_NAMES[$dayInput];
        }

        $seq = null;
        if (blank($row['period'] ?? null) || ! ctype_digit((string) $row['period'])) {
            $errors[] = new ImportError($rowNum, 'period must be a whole number.');
        } else {
            $seq = (int) $row['period'];
        }

        $classCode = null;
        if (blank($row['class_code'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'class_code is required.');
        } else {
            $classCode = ClassCode::where('code', $row['class_code'])->first();
            if ($classCode === null) {
                $errors[] = new ImportError($rowNum, "Unknown class_code \"{$row['class_code']}\".");
            }
        }

        $room = null;
        if (blank($row['room_code'] ?? null)) {
            $errors[] = new ImportError($rowNum, 'room_code is required.');
        } else {
            $room = Room::where('code', $row['room_code'])->first();
            if ($room === null) {
                $errors[] = new ImportError($rowNum, "Unknown room_code \"{$row['room_code']}\".");
            }
        }

        // Slot resolution needs day/seq/validFrom all present and valid.
        $slot = null;
        if ($dayOfWeek !== null && $seq !== null && $validFrom !== null) {
            $slot = PeriodSlot::query()
                ->where('day_of_week', $dayOfWeek)
                ->where('seq', $seq)
                ->whereDate('valid_from', '<=', $validFrom->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom->toDateString()))
                ->first();

            if ($slot === null) {
                $errors[] = new ImportError($rowNum, "No period slot for day={$row['day']}, period={$seq} on {$validFrom->toDateString()}.");
            } elseif ($slot->is_break) {
                $errors[] = new ImportError($rowNum, "day={$row['day']}, period={$seq} is a break slot — cannot assign a class to it.");
            }
        }

        if (count($errors) > $before) {
            return null;
        }

        return [
            'staff_no' => $teacher->staff_no,
            'teacher_id' => $teacher->id,
            'valid_from' => $validFrom->toDateString(),
            'day_of_week' => $dayOfWeek,
            'seq' => $seq,
            'class_code_id' => $classCode->id,
            'room_id' => $room->id,
            'slot_id' => $slot->id,
        ];
    }

    /**
     * @return array{0: int, 1: int} [versions touched, entries written]
     */
    private function applyImport(array $validated, ?int $enteredBy): array
    {
        $byVersion = [];
        foreach ($validated as $row) {
            $key = $row['teacher_id'].'|'.$row['valid_from'];
            $byVersion[$key][] = $row;
        }

        $versionsTouched = 0;
        $entriesWritten = 0;

        DB::transaction(function () use ($byVersion, $enteredBy, &$versionsTouched, &$entriesWritten) {
            foreach ($byVersion as $rows) {
                $first = $rows[0];

                // Not firstOrCreate(['valid_from' => ...]): the `date`
                // cast serialises to "Y-m-d H:i:s" on save, so a raw
                // "Y-m-d" lookup value can silently fail to match an
                // already-saved row (see SessionBuilder::persist() and
                // PeriodResultWriter for the full explanation — same
                // trap, third time today).
                $version = TimetableVersion::query()
                    ->where('teacher_id', $first['teacher_id'])
                    ->whereDate('valid_from', $first['valid_from'])
                    ->first();

                if ($version === null) {
                    $version = TimetableVersion::create([
                        'teacher_id' => $first['teacher_id'],
                        'valid_from' => $first['valid_from'],
                        'state' => TimetableState::Draft,
                        'entered_by' => $enteredBy,
                    ]);
                }
                $versionsTouched++;

                $keepEntryIds = [];
                foreach ($rows as $row) {
                    $entry = TimetableEntry::updateOrCreate(
                        ['version_id' => $version->id, 'day_of_week' => $row['day_of_week'], 'slot_id' => $row['slot_id']],
                        ['class_code_id' => $row['class_code_id'], 'room_id' => $row['room_id']],
                    );
                    $keepEntryIds[] = $entry->id;
                    $entriesWritten++;
                }

                // REPLACE semantics: anything for this version not in the
                // file this time is gone — the file is the complete grid.
                TimetableEntry::where('version_id', $version->id)
                    ->whereNotIn('id', $keepEntryIds)
                    ->delete();
            }
        });

        return [$versionsTouched, $entriesWritten];
    }
}

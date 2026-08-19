<?php

use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\Corridor;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\Import\CsvReader;
use App\Services\Import\TimetableImporter;

function timetableImporter(): TimetableImporter
{
    return new TimetableImporter(new CsvReader);
}

function writeTimetableCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'tt_import_').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['staff_no' => 'T-0001']);
    $this->room = Room::factory()->for(Corridor::factory())->create(['code' => 'A101']);
    $this->classCode = ClassCode::factory()->create(['code' => 'F4A']);
    $this->slot = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => false, 'valid_from' => '2020-01-01']);
});

it('creates a version and entries from valid rows', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeTrue();
    expect(TimetableVersion::count())->toBe(1);
    expect(TimetableEntry::count())->toBe(1);

    $entry = TimetableEntry::first();
    expect($entry->slot_id)->toBe($this->slot->id);
    expect($entry->class_code_id)->toBe($this->classCode->id);
    expect($entry->room_id)->toBe($this->room->id);
});

it('is idempotent: importing the same file twice does not duplicate', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        CSV);

    timetableImporter()->import($path);
    timetableImporter()->import($path);

    expect(TimetableVersion::count())->toBe(1);
    expect(TimetableEntry::count())->toBe(1);
});

it('replaces a version\'s entries to match the file exactly, dropping ones no longer listed', function () {
    $otherSlot = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 2, 'is_break' => false, 'valid_from' => '2020-01-01']);

    $path1 = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        T-0001,2026-09-01,Monday,2,F4A,A101
        CSV);
    timetableImporter()->import($path1);
    expect(TimetableEntry::count())->toBe(2);

    // Re-import the same version with only period 1 — period 2 should disappear.
    $path2 = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        CSV);
    timetableImporter()->import($path2);

    expect(TimetableEntry::count())->toBe(1);
    expect(TimetableEntry::first()->slot_id)->toBe($this->slot->id);
});

it('rejects the whole file when a class_code is unknown, saving nothing', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,NOPE,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('Unknown class_code');
    expect(TimetableEntry::count())->toBe(0);
});

it('rejects an unknown staff_no with a helpful message', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-9999,2026-09-01,Monday,1,F4A,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('import teachers first');
});

it('rejects assigning a class to a break slot', function () {
    PeriodSlot::factory()->create(['day_of_week' => 2, 'seq' => 1, 'is_break' => true, 'valid_from' => '2020-01-01']);

    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Tuesday,1,F4A,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('break slot');
});

it('rejects a duplicate teacher/day/period within the same file', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        T-0001,2026-09-01,Monday,1,F4A,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('Duplicate entry');
});

it('accepts day names case-insensitively', function () {
    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,MONDAY,1,F4A,A101
        CSV);

    $result = timetableImporter()->import($path);

    expect($result->successful())->toBeTrue();
});

it('resolves the period slot version active on valid_from, not just any slot with that day/seq', function () {
    // A newer bell-schedule version for the same day/seq starting later.
    $newerSlot = PeriodSlot::factory()->create([
        'day_of_week' => 1, 'seq' => 1, 'is_break' => false,
        'start_time' => '08:00:00', 'valid_from' => '2027-01-01',
    ]);

    $path = writeTimetableCsv(<<<CSV
        staff_no,valid_from,day,period,class_code,room_code
        T-0001,2026-09-01,Monday,1,F4A,A101
        CSV);
    timetableImporter()->import($path);

    // 2026-09-01 predates the newer slot's valid_from, so the original slot applies.
    expect(TimetableEntry::first()->slot_id)->toBe($this->slot->id);
    expect(TimetableEntry::first()->slot_id)->not->toBe($newerSlot->id);
});

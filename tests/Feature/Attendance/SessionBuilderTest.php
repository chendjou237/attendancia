<?php

use App\Models\AttendanceSession;
use App\Models\ClassCode;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\Attendance\SessionBuilder;
use Tests\Support\AttendanceFixture;

// §4: double period, break-separated merge, then a free-slot split —
// the exact scenario from the spec's own example.
it('merges consecutive periods and break-separated periods into one session, but splits on a free slot', function () {
    $f = AttendanceFixture::make([
        ['07:30:00', '08:25:00'],           // seq 1
        ['08:25:00', '09:20:00'],           // seq 2
        ['09:20:00', '09:35:00', true],     // seq 3, break
        ['09:35:00', '10:30:00'],           // seq 4
        // seq 5 (10:30-11:25) deliberately has no timetable_entry: free/unscheduled
    ]);

    // seq 5 must exist in period_slots (so the builder can see it's not a
    // break) even though no entry uses it.
    \App\Models\PeriodSlot::factory()->create([
        'day_of_week' => 1, 'seq' => 5, 'start_time' => '10:30:00', 'end_time' => '11:25:00', 'is_break' => false,
    ]);
    $slot6 = \App\Models\PeriodSlot::factory()->create([
        'day_of_week' => 1, 'seq' => 6, 'start_time' => '11:25:00', 'end_time' => '12:20:00', 'is_break' => false,
    ]);
    TimetableEntry::factory()->for($f->timetableVersion, 'version')->create([
        'day_of_week' => 1, 'slot_id' => $slot6->id, 'class_code_id' => $f->classCode->id, 'room_id' => $f->room->id,
    ]);

    $groupings = (new SessionBuilder)->group($f->teacher, $f->date);

    expect($groupings)->toHaveCount(2);
    expect($groupings[0]->firstSlot->seq)->toBe(1);
    expect($groupings[0]->lastSlot->seq)->toBe(4);
    expect($groupings[0]->periodSlotIds)->toHaveCount(3); // seq 1, 2, 4 — the break itself never gets an entry
    expect($groupings[1]->firstSlot->seq)->toBe(6);
    expect($groupings[1]->lastSlot->seq)->toBe(6);
});

it('splits into a new session on a class-code change, even back-to-back with no gap', function () {
    $f = AttendanceFixture::make([
        ['07:30:00', '08:25:00'],
        ['08:25:00', '09:20:00'],
    ]);

    // Overwrite the second slot's entry to a different class code.
    $otherClass = ClassCode::factory()->create();
    $secondSlot = \App\Models\PeriodSlot::where('seq', 2)->first();
    TimetableEntry::where('slot_id', $secondSlot->id)->update(['class_code_id' => $otherClass->id]);

    $groupings = (new SessionBuilder)->group($f->teacher, $f->date);

    expect($groupings)->toHaveCount(2);
});

it('is idempotent: persisting the same groupings twice reuses the same rows', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);
    $builder = new SessionBuilder;

    $sessions1 = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date));
    $sessions2 = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date));

    expect(AttendanceSession::count())->toBe(1);
    expect($sessions2->first()->id)->toBe($sessions1->first()->id);
});

// The "I made a new timetable and attendance still uses the old one"
// report: the timetable screen defaults a new version's valid_from to
// today, so a replacement grid routinely shares a valid_from with the
// version it replaces. Ordering on valid_from alone left the winner to
// the database, which returned the older row.
it('builds from the newest version when two open versions share a valid_from', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);

    $newClass = ClassCode::factory()->create();
    $newer = TimetableVersion::factory()->for($f->teacher)->create([
        'valid_from' => $f->timetableVersion->valid_from->toDateString(),
        'valid_to' => null,
    ]);
    TimetableEntry::factory()->for($newer, 'version')->create([
        'day_of_week' => 1,
        'slot_id' => $f->slot->id,
        'class_code_id' => $newClass->id,
        'room_id' => $f->room->id,
    ]);

    expect($f->teacher->timetableVersionFor($f->date)->id)->toBe($newer->id);

    $groupings = (new SessionBuilder)->group($f->teacher, $f->date);

    expect($groupings)->toHaveCount(1);
    expect($groupings[0]->classCodeId)->toBe($newClass->id);
});

// Closing a version is how a timetable is cancelled (the "Close
// timetable" button): the window ends, and the version covering the
// dates after it governs again — nothing is deleted.
it('falls back to the previous version once the newer one is closed', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);

    $newer = TimetableVersion::factory()->for($f->teacher)->create([
        'valid_from' => $f->timetableVersion->valid_from->toDateString(),
        'valid_to' => $f->date->clone()->subDay()->toDateString(),
    ]);

    expect($f->teacher->timetableVersionFor($f->date)->id)->toBe($f->timetableVersion->id);
    expect($newer->fresh()->exists)->toBeTrue();
});

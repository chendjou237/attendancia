<?php

use App\Filament\Resources\Teachers\Pages\ManageTimetable;
use App\Models\AuditLog;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('shows a prompt to create a version when the teacher has none', function () {
    $teacher = Teacher::factory()->create();

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->assertSee('No timetable version exists yet');
});

it('creates a new version and saves a filled cell as a timetable entry', function () {
    $teacher = Teacher::factory()->create();
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    $slot = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => false]);

    $component = Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', '2026-09-01')
        ->call('createVersion');

    expect(TimetableVersion::where('teacher_id', $teacher->id)->count())->toBe(1);
    $version = TimetableVersion::first();

    $component
        ->set("cells.1.1.class_code_id", $classCode->id)
        ->set("cells.1.1.room_id", $room->id)
        ->call('save');

    $entry = TimetableEntry::where('version_id', $version->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry->slot_id)->toBe($slot->id);
    expect($entry->class_code_id)->toBe($classCode->id);
    expect($entry->room_id)->toBe($room->id);
});

it('is idempotent: saving the same grid twice does not duplicate entries', function () {
    $teacher = Teacher::factory()->create();
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => false]);

    $component = Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', '2026-09-01')
        ->call('createVersion')
        ->set("cells.1.1.class_code_id", $classCode->id)
        ->set("cells.1.1.room_id", $room->id)
        ->call('save');

    expect(TimetableEntry::count())->toBe(1);

    $component->call('save');

    expect(TimetableEntry::count())->toBe(1);
});

it('clearing a cell and saving removes the entry', function () {
    $teacher = Teacher::factory()->create();
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => false]);

    $component = Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', '2026-09-01')
        ->call('createVersion')
        ->set("cells.1.1.class_code_id", $classCode->id)
        ->set("cells.1.1.room_id", $room->id)
        ->call('save');

    expect(TimetableEntry::count())->toBe(1);

    $component
        ->set("cells.1.1.class_code_id", '')
        ->set("cells.1.1.room_id", '')
        ->call('save');

    expect(TimetableEntry::count())->toBe(0);
});

it('never writes an entry for a break slot even if a stray value were set', function () {
    $teacher = Teacher::factory()->create();
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => true]);

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', '2026-09-01')
        ->call('createVersion')
        ->set("cells.1.1.class_code_id", $classCode->id)
        ->set("cells.1.1.room_id", $room->id)
        ->call('save');

    expect(TimetableEntry::count())->toBe(0);
});

it('creating a new version clones entries from the previously selected version', function () {
    $teacher = Teacher::factory()->create();
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    $slot = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1, 'is_break' => false]);

    $oldVersion = TimetableVersion::factory()->for($teacher)->create(['valid_from' => '2026-01-01']);
    TimetableEntry::factory()->for($oldVersion, 'version')->create([
        'day_of_week' => 1, 'slot_id' => $slot->id, 'class_code_id' => $classCode->id, 'room_id' => $room->id,
    ]);

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', '2026-09-01')
        ->call('createVersion');

    $newVersion = TimetableVersion::whereDate('valid_from', '2026-09-01')->first();
    expect(TimetableEntry::where('version_id', $newVersion->id)->count())->toBe(1);

    $clonedEntry = TimetableEntry::where('version_id', $newVersion->id)->first();
    expect($clonedEntry->class_code_id)->toBe($classCode->id);
    expect($clonedEntry->room_id)->toBe($room->id);
});

// The reported bug: a new grid was entered, and Teacher Attendance kept
// showing results computed under the old one because nothing recomputed
// them. Saving now runs attendance:compute for the dates the version
// governs, scoped to this teacher.
it('recomputes stored attendance for today when the grid is saved', function () {
    $today = Carbon::today();
    $teacher = Teacher::factory()->create(['active_from' => $today->clone()->subYear()->toDateString()]);
    $classCode = ClassCode::factory()->create();
    $room = Room::factory()->create();
    PeriodSlot::factory()->create([
        'day_of_week' => $today->dayOfWeek,
        'seq' => 1,
        'is_break' => false,
        'valid_from' => $today->clone()->subYear()->toDateString(),
    ]);
    RuleVersion::factory()->create();

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('newVersionValidFrom', $today->toDateString())
        ->call('createVersion')
        ->set("cells.{$today->dayOfWeek}.1.class_code_id", $classCode->id)
        ->set("cells.{$today->dayOfWeek}.1.room_id", $room->id)
        ->call('save');

    $result = PeriodResult::query()
        ->current()
        ->where('teacher_id', $teacher->id)
        ->where('date', $today->toDateString())
        ->first();

    expect($result)->not->toBeNull();
    expect($result->class_code_id)->toBe($classCode->id);
});

it('closes the selected version, audits it, and hands the dates back to the previous version', function () {
    $today = Carbon::today();
    $teacher = Teacher::factory()->create();
    $previous = TimetableVersion::factory()->for($teacher)->create([
        'valid_from' => $today->clone()->subYear()->toDateString(),
    ]);
    $newer = TimetableVersion::factory()->for($teacher)->create([
        'valid_from' => $today->toDateString(),
    ]);
    RuleVersion::factory()->create();

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->assertSet('versionId', $newer->id)
        ->set('closeValidTo', $today->toDateString())
        ->call('closeVersion')
        ->assertHasNoErrors();

    expect($newer->fresh()->valid_to->toDateString())->toBe($today->toDateString());
    expect($teacher->timetableVersionFor($today->clone()->addDay())->id)->toBe($previous->id);

    $audit = AuditLog::where('entity', 'timetable_versions')->where('entity_id', $newer->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('closed');
    expect($audit->actor_id)->not->toBeNull();
});

it('refuses to close a version before the day it starts', function () {
    $today = Carbon::today();
    $teacher = Teacher::factory()->create();
    $version = TimetableVersion::factory()->for($teacher)->create(['valid_from' => $today->toDateString()]);

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('closeValidTo', $today->clone()->subDay()->toDateString())
        ->call('closeVersion')
        ->assertHasErrors('closeValidTo');

    expect($version->fresh()->valid_to)->toBeNull();
});

// Closing a grid changes today too, not just the days inside the closed
// window: once valid_to is in the past, today is handed back to the
// previous version, so today's stored result has to be recomputed
// against that older grid.
it('recomputes days after the closing date against the version handed back to', function () {
    $today = Carbon::today();
    $teacher = Teacher::factory()->create(['active_from' => $today->clone()->subYear()->toDateString()]);
    $oldClass = ClassCode::factory()->create();
    $newClass = ClassCode::factory()->create();
    $room = Room::factory()->create();
    $slot = PeriodSlot::factory()->create([
        'day_of_week' => $today->dayOfWeek,
        'seq' => 1,
        'is_break' => false,
        'valid_from' => $today->clone()->subYear()->toDateString(),
    ]);
    RuleVersion::factory()->create();

    $previous = TimetableVersion::factory()->for($teacher)->create([
        'valid_from' => $today->clone()->subYear()->toDateString(),
    ]);
    TimetableEntry::factory()->for($previous, 'version')->create([
        'day_of_week' => $today->dayOfWeek,
        'slot_id' => $slot->id,
        'class_code_id' => $oldClass->id,
        'room_id' => $room->id,
    ]);

    $newer = TimetableVersion::factory()->for($teacher)->create([
        'valid_from' => $today->clone()->subDay()->toDateString(),
    ]);
    TimetableEntry::factory()->for($newer, 'version')->create([
        'day_of_week' => $today->dayOfWeek,
        'slot_id' => $slot->id,
        'class_code_id' => $newClass->id,
        'room_id' => $room->id,
    ]);

    Artisan::call('attendance:compute', ['date' => $today->toDateString(), '--teacher' => [$teacher->staff_no]]);

    expect(PeriodResult::current()->where('teacher_id', $teacher->id)->first()->class_code_id)
        ->toBe($newClass->id);

    Livewire::test(ManageTimetable::class, ['record' => $teacher->getKey()])
        ->set('closeValidTo', $today->clone()->subDay()->toDateString())
        ->call('closeVersion')
        ->assertHasNoErrors();

    expect(PeriodResult::current()->where('teacher_id', $teacher->id)->first()->class_code_id)
        ->toBe($oldClass->id);
});

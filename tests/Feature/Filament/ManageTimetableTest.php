<?php

use App\Filament\Resources\Teachers\Pages\ManageTimetable;
use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
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

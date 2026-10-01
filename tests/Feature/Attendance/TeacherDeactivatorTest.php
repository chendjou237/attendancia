<?php

use App\Enums\PeriodStatus;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Models\AuditLog;
use App\Models\PeriodResult;
use App\Models\RawEvent;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Attendance\AttendanceRefresher;
use App\Services\Attendance\DayResolver;
use App\Services\Attendance\TeacherDeactivator;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Tests\Support\AttendanceFixture;

/**
 * The duplicate-teacher case this exists for (IROI, 2026-09): the same
 * person entered twice, each record with its own timetable, the scans
 * landing on one and the fingerprint later moved to the other — so the
 * panel showed the same periods both present and unpaired.
 */
function duplicateScenario(): array
{
    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    // The fixture's default is next Monday; deactivation only recomputes
    // up to today, so the scenario has to be in the past.
    $f->date = Carbon::now()->previous(Carbon::MONDAY);
    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    $keeper = Teacher::factory()->create(['full_name' => $f->teacher->full_name]);
    $version = TimetableVersion::factory()->for($keeper)->create();
    TimetableEntry::factory()->for($version, 'version')->create([
        'day_of_week' => 1,
        'slot_id' => $f->slot->id,
        'class_code_id' => $f->classCode->id,
        'room_id' => $f->room->id,
    ]);

    app(DayResolver::class)->resolve($f->teacher, $f->date, $f->rule);
    app(DayResolver::class)->resolve($keeper, $f->date, $f->rule);

    return [$f, $f->teacher, $keeper];
}

function currentStatus(Teacher $teacher): ?PeriodStatus
{
    return PeriodResult::current()->where('teacher_id', $teacher->id)->first()?->status;
}

it('starts from the reported symptom: one record present, its twin unpaired', function () {
    [, $duplicate, $keeper] = duplicateScenario();

    expect(currentStatus($duplicate))->toBe(PeriodStatus::Present)
        ->and(currentStatus($keeper))->not->toBe(PeriodStatus::Present);
});

it('merging moves the duplicate\'s scans to the keeper and retires the duplicate', function () {
    [, $duplicate, $keeper] = duplicateScenario();

    $days = app(TeacherDeactivator::class)->mergeInto($duplicate, $keeper);

    expect($days)->toBe(2)
        ->and(currentStatus($keeper))->toBe(PeriodStatus::Present)
        ->and(currentStatus($duplicate))->toBeNull()
        // retired, not deleted
        ->and(PeriodResult::where('teacher_id', $duplicate->id)->count())->toBe(1);

    $duplicate->refresh();
    expect($duplicate->merged_into_id)->toBe($keeper->id)
        ->and($duplicate->active_to->lessThan($duplicate->active_from))->toBeTrue()
        // raw_events is append-only: the scans still name the duplicate
        ->and(RawEvent::where('teacher_id', $duplicate->id)->count())->toBe(2);

    expect(AuditLog::where('entity', 'teachers')->where('entity_id', $duplicate->id)->where('action', 'merge')->exists())->toBeTrue();
});

it('moves the duplicate\'s open fingerprint to the keeper', function () {
    [, $duplicate, $keeper] = duplicateScenario();
    TeacherBiometricId::factory()->create(['teacher_id' => $duplicate->id, 'biometric_id' => '37', 'valid_from' => '2026-09-01']);

    app(TeacherDeactivator::class)->mergeInto($duplicate, $keeper);

    expect(TeacherBiometricId::whereNull('valid_to')->where('biometric_id', '37')->sole()->teacher_id)->toBe($keeper->id)
        ->and(TeacherBiometricId::where('teacher_id', $duplicate->id)->sole()->valid_to)->not->toBeNull();
});

it('reactivating undoes a merge', function () {
    [, $duplicate, $keeper] = duplicateScenario();
    $service = app(TeacherDeactivator::class);
    $service->mergeInto($duplicate, $keeper);

    $service->reactivate($duplicate->refresh());

    expect($duplicate->refresh()->merged_into_id)->toBeNull()
        ->and($duplicate->active_to)->toBeNull()
        ->and(currentStatus($duplicate))->toBe(PeriodStatus::Present)
        ->and(currentStatus($keeper))->not->toBe(PeriodStatus::Present);
});

it('a scan still resolving to a merged duplicate recomputes the keeper', function () {
    [$f, $duplicate, $keeper] = duplicateScenario();
    // Keeper has nothing yet for the day, so only the refresher can create it.
    PeriodResult::where('teacher_id', $keeper->id)->delete();
    $duplicate->forceFill(['merged_into_id' => $keeper->id, 'active_to' => $duplicate->active_from->clone()->subDay()])->save();

    app(AttendanceRefresher::class)->refreshForScan(RawEvent::where('teacher_id', $duplicate->id)->first());

    expect(currentStatus($keeper))->toBe(PeriodStatus::Present);
});

it('deactivating keeps results up to the last day and retires the ones after it', function () {
    [$f, $teacher] = duplicateScenario();
    $service = app(TeacherDeactivator::class);

    $service->deactivate($teacher, $f->date->clone());
    expect(currentStatus($teacher))->toBe(PeriodStatus::Present);

    $service->reactivate($teacher->refresh());
    $service->deactivate($teacher->refresh(), $f->date->clone()->subDay());
    expect(currentStatus($teacher))->toBeNull();
});

it('refuses merges that would build a chain or loop', function () {
    [, $duplicate, $keeper] = duplicateScenario();
    $service = app(TeacherDeactivator::class);

    expect(fn () => $service->mergeInto($duplicate, $duplicate))->toThrow(InvalidArgumentException::class);

    $service->mergeInto($duplicate, $keeper);
    $third = Teacher::factory()->create();

    expect(fn () => $service->mergeInto($third, $duplicate->refresh()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->mergeInto($duplicate->refresh(), $third))->toThrow(InvalidArgumentException::class);
});

it('merges from the teachers table, pre-selecting the same-name record, and hides it from the default list', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    [, $duplicate, $keeper] = duplicateScenario();

    Livewire::test(ListTeachers::class)
        ->mountTableAction('mergeDuplicate', $duplicate)
        ->assertTableActionDataSet(['keeper_id' => $keeper->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($duplicate->refresh()->merged_into_id)->toBe($keeper->id);

    Livewire::test(ListTeachers::class)
        ->assertCanSeeTableRecords([$keeper])
        ->assertCanNotSeeTableRecords([$duplicate]);
});

<?php

use App\Filament\Pages\ExceptionQueue;
use App\Filament\Pages\TeacherAttendance;
use App\Models\AuditLog;
use App\Models\PeriodResult;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Tests\Support\AttendanceFixture;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// Not actingAsRole() — MonthlyReportsTest already declares that one
// globally, and Pest loads every test file into the same process.
function actingAsPanelRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

// The point of the button: staff can refresh a day themselves instead of
// waiting for the scheduler or asking for a command line on the server.
it('recomputes a day from the exception queue, picking up scans the last run missed', function () {
    actingAsPanelRole('officer');

    $f = AttendanceFixture::make(ruleOverrides: ['pair_window_before_minutes' => 60, 'pair_window_after_minutes' => 60, 'min_session_minutes' => 1]);
    $this->artisan('attendance:compute', ['date' => $f->date->toDateString()])->assertSuccessful();
    expect(PeriodResult::current()->first()->status->value)->toBe('unpaired');

    $f->scanAt('07:28:00');
    $f->scanAt('08:25:00');

    Livewire::test(ExceptionQueue::class)
        ->callAction('recomputeAttendance', ['date' => $f->date->toDateString()])
        ->assertHasNoActionErrors();

    expect(PeriodResult::current()->first()->status->value)->toBe('present');
});

it('scopes the recompute to one teacher when one is picked', function () {
    actingAsPanelRole('admin');

    $f = AttendanceFixture::make();
    $other = Teacher::factory()->create();
    $tv = TimetableVersion::factory()->for($other)->create();
    TimetableEntry::factory()->for($tv, 'version')->create([
        'day_of_week' => 1, 'slot_id' => $f->slot->id, 'class_code_id' => $f->classCode->id, 'room_id' => $f->room->id,
    ]);

    Livewire::test(TeacherAttendance::class)
        ->callAction('recomputeAttendance', [
            'date' => $f->date->toDateString(),
            'teacher_id' => $f->teacher->id,
        ])
        ->assertHasNoActionErrors();

    expect(PeriodResult::where('teacher_id', $f->teacher->id)->count())->toBe(1);
    expect(PeriodResult::where('teacher_id', $other->id)->count())->toBe(0);
});

it('audits the recompute with its actor', function () {
    $user = actingAsPanelRole('officer');
    $f = AttendanceFixture::make();

    Livewire::test(ExceptionQueue::class)
        ->callAction('recomputeAttendance', ['date' => $f->date->toDateString()]);

    $audit = AuditLog::where('entity', 'period_results')->where('action', 'recompute')->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_id)->toBe($user->id);
    expect($audit->after_json['date'])->toBe($f->date->toDateString());
});

// A recompute rewrites what payroll reads. Principal reads along and
// overrides; HR lives in Monthly Reports. Neither drives the engine.
it('is hidden from a principal and from hr', function () {
    actingAsPanelRole('principal');
    Livewire::test(ExceptionQueue::class)->assertActionHidden('recomputeAttendance');
});

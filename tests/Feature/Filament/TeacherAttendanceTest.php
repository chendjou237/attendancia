<?php

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Filament\Pages\TeacherAttendance;
use App\Models\AttendanceSession;
use App\Models\PeriodResult;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

function periodResultFor(Teacher $teacher, string $date, array $overrides = []): PeriodResult
{
    return PeriodResult::factory()->create(array_merge([
        'teacher_id' => $teacher->id,
        'date' => $date,
        'status' => PeriodStatus::Present,
        'source' => PeriodSource::Scan,
        'is_current' => true,
    ], $overrides));
}

it('shows every current teacher\'s periods for today by default, with no teacher pre-selected', function () {
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    $todayA = periodResultFor($teacherA, today()->toDateString());
    $todayB = periodResultFor($teacherB, today()->toDateString());
    $yesterday = periodResultFor($teacherA, today()->subDay()->toDateString());

    Livewire::test(TeacherAttendance::class)
        ->assertCanSeeTableRecords([$todayA, $todayB])
        ->assertCanNotSeeTableRecords([$yesterday]);
});

it('excludes a period result that is no longer current', function () {
    $teacher = Teacher::factory()->create();
    $current = periodResultFor($teacher, today()->toDateString());
    $stale = periodResultFor($teacher, today()->toDateString(), ['is_current' => false]);

    Livewire::test(TeacherAttendance::class)
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$stale]);
});

it('narrows to a single teacher via the teacher filter', function () {
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $resultA = periodResultFor($teacherA, today()->toDateString());
    $resultB = periodResultFor($teacherB, today()->toDateString());

    Livewire::test(TeacherAttendance::class)
        ->filterTable('teacher', $teacherA)
        ->assertCanSeeTableRecords([$resultA])
        ->assertCanNotSeeTableRecords([$resultB]);
});

it('narrows to a date range via the date filter', function () {
    $teacher = Teacher::factory()->create();
    $before = periodResultFor($teacher, '2026-01-01');
    $middle = periodResultFor($teacher, '2026-01-15');
    $after = periodResultFor($teacher, '2026-01-31');

    Livewire::test(TeacherAttendance::class)
        ->filterTable('date_range', ['from' => '2026-01-15', 'to' => '2026-01-15'])
        ->assertCanSeeTableRecords([$middle])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('treats a blank date bound as unbounded on that side', function () {
    $teacher = Teacher::factory()->create();
    $farPast = periodResultFor($teacher, '2020-01-01');
    $todayResult = periodResultFor($teacher, today()->toDateString());

    Livewire::test(TeacherAttendance::class)
        ->filterTable('date_range', ['from' => null, 'to' => today()->toDateString()])
        ->assertCanSeeTableRecords([$farPast, $todayResult]);
});

it('combines the teacher filter and the date range filter', function () {
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();
    $match = periodResultFor($teacherA, '2026-03-10');
    $wrongTeacher = periodResultFor($teacherB, '2026-03-10');
    $wrongDate = periodResultFor($teacherA, '2026-03-11');

    Livewire::test(TeacherAttendance::class)
        ->filterTable('teacher', $teacherA)
        ->filterTable('date_range', ['from' => '2026-03-10', 'to' => '2026-03-10'])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongTeacher, $wrongDate]);
});

it('sorts most-recent-first by default', function () {
    $teacher = Teacher::factory()->create();
    $oldest = periodResultFor($teacher, '2026-01-01');
    $newest = periodResultFor($teacher, '2026-03-01');
    $middle = periodResultFor($teacher, '2026-02-01');

    Livewire::test(TeacherAttendance::class)
        ->filterTable('date_range', ['from' => null, 'to' => null])
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

it('shows the effective status, preferring an override over the computed status', function () {
    $teacher = Teacher::factory()->create();
    $result = periodResultFor($teacher, today()->toDateString(), [
        'status' => PeriodStatus::Unpaired,
        'override_status' => PeriodStatus::Present,
    ]);

    Livewire::test(TeacherAttendance::class)
        ->assertTableColumnStateSet('status', PeriodStatus::Present, $result);
});

it('translates the pending reason instead of showing the raw anomaly code', function () {
    $teacher = Teacher::factory()->create();
    $session = AttendanceSession::factory()->create([
        'teacher_id' => $teacher->id,
        'state' => SessionState::Unpaired,
        'anomaly_code' => SessionAnomaly::NoScanIn->value,
    ]);
    $result = periodResultFor($teacher, today()->toDateString(), [
        'status' => PeriodStatus::Unpaired,
        'session_id' => $session->id,
    ]);

    Livewire::test(TeacherAttendance::class)
        ->assertTableColumnFormattedStateSet('session.anomaly_code', SessionAnomaly::NoScanIn->getLabel(), $result);
});

it('shows a placeholder, not a badge, when there is no anomaly to explain', function () {
    $teacher = Teacher::factory()->create();
    $result = periodResultFor($teacher, today()->toDateString());

    Livewire::test(TeacherAttendance::class)
        ->assertTableColumnFormattedStateSet('session.anomaly_code', null, $result);
});

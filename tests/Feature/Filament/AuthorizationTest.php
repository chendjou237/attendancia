<?php

use App\Filament\Pages\ExceptionQueue;
use App\Filament\Pages\TeacherAttendance;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\CalendarDays\CalendarDayResource;
use App\Filament\Resources\ClassCodes\ClassCodeResource;
use App\Filament\Resources\Corridors\CorridorResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\MonthlyReports\MonthlyReportResource;
use App\Filament\Resources\MonthlyReports\Pages\ListMonthlyReports;
use App\Filament\Resources\Notices\NoticeResource;
use App\Filament\Resources\PeriodSlots\PeriodSlotResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\RuleVersions\RuleVersionResource;
use App\Filament\Resources\TeacherBiometricIds\TeacherBiometricIdResource;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Filament\Resources\Teachers\TeacherResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/**
 * §2 of the task: before this, canViewAny/canAccess had no per-role
 * gating anywhere — any logged-in user saw every Filament resource.
 * These tests pin the role boundaries documented in docs/onboarding.md's
 * "Logging in" section.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function loginAs(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

// Reference data that stays admin-only (docs/onboarding.md §1) — none of
// these are called out for officer/principal/hr.
dataset('adminOnlyResources', [
    'corridors' => [CorridorResource::class],
    'rooms' => [RoomResource::class],
    'class codes' => [ClassCodeResource::class],
    'devices' => [DeviceResource::class],
    'notices' => [NoticeResource::class],
    'calendar days' => [CalendarDayResource::class],
    'rule versions' => [RuleVersionResource::class],
    'users' => [UserResource::class],
    'period slots' => [PeriodSlotResource::class],
]);

it('lets admin view admin-only resources', function (string $resourceClass) {
    loginAs('admin');

    $this->get($resourceClass::getUrl('index'))->assertSuccessful();
})->with('adminOnlyResources');

it('blocks officer from admin-only resources', function (string $resourceClass) {
    loginAs('officer');

    $this->get($resourceClass::getUrl('index'))->assertForbidden();
})->with('adminOnlyResources');

it('blocks principal from admin-only resources', function (string $resourceClass) {
    loginAs('principal');

    $this->get($resourceClass::getUrl('index'))->assertForbidden();
})->with('adminOnlyResources');

it('blocks hr from admin-only resources', function (string $resourceClass) {
    loginAs('hr');

    $this->get($resourceClass::getUrl('index'))->assertForbidden();
})->with('adminOnlyResources');

it('lets officer and admin manage teacher biometric ids, blocks principal and hr', function () {
    loginAs('officer');
    $this->get(TeacherBiometricIdResource::getUrl('index'))->assertSuccessful();

    loginAs('admin');
    $this->get(TeacherBiometricIdResource::getUrl('index'))->assertSuccessful();

    loginAs('principal');
    $this->get(TeacherBiometricIdResource::getUrl('index'))->assertForbidden();

    loginAs('hr');
    $this->get(TeacherBiometricIdResource::getUrl('index'))->assertForbidden();
});

it('lets officer, principal, and admin reach the exception queue, blocks hr', function () {
    loginAs('officer');
    $this->get(ExceptionQueue::getUrl())->assertSuccessful();

    loginAs('principal');
    $this->get(ExceptionQueue::getUrl())->assertSuccessful();

    loginAs('admin');
    $this->get(ExceptionQueue::getUrl())->assertSuccessful();

    loginAs('hr');
    $this->get(ExceptionQueue::getUrl())->assertForbidden();
});

it('lets officer, principal, and admin reach teacher attendance, blocks hr', function () {
    loginAs('officer');
    $this->get(TeacherAttendance::getUrl())->assertSuccessful();

    loginAs('principal');
    $this->get(TeacherAttendance::getUrl())->assertSuccessful();

    loginAs('admin');
    $this->get(TeacherAttendance::getUrl())->assertSuccessful();

    loginAs('hr');
    $this->get(TeacherAttendance::getUrl())->assertForbidden();
});

it('lets principal and admin view the audit log, blocks officer and hr', function () {
    loginAs('admin');
    $this->get(AuditLogResource::getUrl('index'))->assertSuccessful();

    loginAs('principal');
    $this->get(AuditLogResource::getUrl('index'))->assertSuccessful();

    loginAs('officer');
    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();

    loginAs('hr');
    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
});

it('lets officer browse teachers and reach manage timetable, but not create or edit a teacher', function () {
    $teacher = Teacher::factory()->create();
    loginAs('officer');

    $this->get(TeacherResource::getUrl('index'))->assertSuccessful();
    $this->get(TeacherResource::getUrl('timetable', ['record' => $teacher]))->assertSuccessful();
    $this->get(TeacherResource::getUrl('create'))->assertForbidden();
    $this->get(TeacherResource::getUrl('edit', ['record' => $teacher]))->assertForbidden();

    // Guards against a real gap: Filament's CreateAction/EditAction/
    // DeleteBulkAction buttons resolve visibility through
    // getCreateAuthorizationResponse()/getEditAuthorizationResponse(),
    // not through the plain canCreate()/canEdit() booleans — overriding
    // only the booleans leaves the buttons visible (though still
    // 403-blocked on click) for a role that can view but not mutate.
    Livewire::test(ListTeachers::class)
        ->assertActionHidden('create')
        ->assertTableActionHidden('edit', $teacher);
});

it('lets admin fully manage teachers, including create and edit', function () {
    $teacher = Teacher::factory()->create();
    loginAs('admin');

    $this->get(TeacherResource::getUrl('index'))->assertSuccessful();
    $this->get(TeacherResource::getUrl('create'))->assertSuccessful();
    $this->get(TeacherResource::getUrl('edit', ['record' => $teacher]))->assertSuccessful();
    $this->get(TeacherResource::getUrl('timetable', ['record' => $teacher]))->assertSuccessful();

    Livewire::test(ListTeachers::class)
        ->assertActionVisible('create')
        ->assertTableActionVisible('edit', $teacher);
});

it('blocks principal and hr from teachers entirely', function (string $role) {
    $teacher = Teacher::factory()->create();
    loginAs($role);

    $this->get(TeacherResource::getUrl('index'))->assertForbidden();
    $this->get(TeacherResource::getUrl('timetable', ['record' => $teacher]))->assertForbidden();
})->with(['principal', 'hr']);

it('lets every role view monthly reports, but only admin and officer generate one', function (string $role) {
    loginAs($role);

    $this->get(MonthlyReportResource::getUrl('index'))->assertSuccessful();
})->with(['admin', 'officer', 'principal', 'hr']);

it('shows the generate action to admin and officer, hides it from principal and hr', function () {
    loginAs('admin');
    Livewire::test(ListMonthlyReports::class)->assertActionVisible('generate');

    loginAs('officer');
    Livewire::test(ListMonthlyReports::class)->assertActionVisible('generate');

    loginAs('principal');
    Livewire::test(ListMonthlyReports::class)->assertActionHidden('generate');

    loginAs('hr');
    Livewire::test(ListMonthlyReports::class)->assertActionHidden('generate');
});

<?php

use App\Filament\Resources\Teachers\Pages\EditTeacher;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\PeriodResult;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/**
 * Pins the guards added after a bulk delete on Users removed every login,
 * the acting admin's included, and teacher deletes surfaced as a raw
 * foreign-key error (App\Filament\Actions\GuardedDelete).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('refuses to delete the acting user from the edit page', function () {
    $admin = userWithRole('admin');
    userWithRole('admin');
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->callAction('delete')
        ->assertNotified(__('panel.actions.delete_refused'));

    expect(User::find($admin->id))->not->toBeNull()
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue();
});

it('refuses to delete the last admin', function () {
    // Nobody is acting, so only the last-admin rule can apply — in the panel
    // a second admin would be needed to reach someone else's delete button.
    $lastAdmin = userWithRole('admin');
    expect($lastAdmin->deletionBlockedReason())->toBe(__('panel.resources.users.cannot_delete_last_admin'));

    userWithRole('admin');
    expect($lastAdmin->deletionBlockedReason())->toBeNull();
});

it('bulk-deletes everyone else but keeps the acting admin, roles intact', function () {
    $admin = userWithRole('admin');
    $officer = userWithRole('officer');
    $otherAdmin = userWithRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('delete', User::all());

    expect(User::pluck('id')->all())->toBe([$admin->id])
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue();
});

it('audits a user deletion', function () {
    $admin = userWithRole('admin');
    $officer = userWithRole('officer');
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('delete', [$officer]);

    $entry = AuditLog::where('entity', 'users')->where('entity_id', $officer->id)->sole();
    expect($entry->action)->toBe('delete')
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->before_json['email'])->toBe($officer->email);
});

it('refuses to delete a teacher with recorded attendance, without a database error', function () {
    $this->actingAs(userWithRole('admin'));
    $withResults = Teacher::factory()->create();
    PeriodResult::factory()->create(['teacher_id' => $withResults->id]);
    $withSession = Teacher::factory()->create();
    AttendanceSession::factory()->create(['teacher_id' => $withSession->id]);
    $unused = Teacher::factory()->create();

    Livewire::test(ListTeachers::class)
        ->callTableBulkAction('delete', [$withResults, $withSession, $unused]);

    expect(Teacher::pluck('id')->sort()->values()->all())
        ->toBe(collect([$withResults->id, $withSession->id])->sort()->values()->all());
});

it('refuses a used teacher from the edit page with a notification', function () {
    $this->actingAs(userWithRole('admin'));
    $teacher = Teacher::factory()->create();
    PeriodResult::factory()->create(['teacher_id' => $teacher->id]);

    Livewire::test(EditTeacher::class, ['record' => $teacher->getRouteKey()])
        ->callAction('delete')
        ->assertNotified(__('panel.actions.delete_refused'));

    expect(Teacher::find($teacher->id))->not->toBeNull();
});

it('still deletes a teacher that was never used', function () {
    $this->actingAs(userWithRole('admin'));
    $teacher = Teacher::factory()->create();

    Livewire::test(EditTeacher::class, ['record' => $teacher->getRouteKey()])
        ->callAction('delete');

    expect(Teacher::find($teacher->id))->toBeNull();
});

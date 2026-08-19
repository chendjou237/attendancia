<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('creates a user through the admin panel and assigns the selected role', function () {
    $officerRoleId = Role::where('name', 'officer')->value('id');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Officer',
            'email' => 'jane@attendancia.test',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'roles' => $officerRoleId,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane@attendancia.test')->firstOrFail();

    expect($user->hasRole('officer'))->toBeTrue();
    expect($user->hasRole('admin'))->toBeFalse();
    expect(Hash::check('a-real-password', $user->password))->toBeTrue();
});

it('requires the password confirmation to match', function () {
    $officerRoleId = Role::where('name', 'officer')->value('id');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Officer',
            'email' => 'jane2@attendancia.test',
            'password' => 'a-real-password',
            'password_confirmation' => 'does-not-match',
            'roles' => $officerRoleId,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'jane2@attendancia.test')->exists())->toBeFalse();
});

it('requires a role to be assigned', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'No Role',
            'email' => 'norole@attendancia.test',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'roles' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::where('email', 'norole@attendancia.test')->exists())->toBeFalse();
});

it('leaves the password unchanged on edit when the field is left blank', function () {
    $officerRoleId = Role::where('name', 'officer')->value('id');
    $user = User::factory()->create(['password' => Hash::make('original-password')]);
    $user->assignRole('officer');

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => 'Renamed',
            'email' => $user->email,
            'password' => null,
            'password_confirmation' => null,
            'roles' => $officerRoleId,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->name)->toBe('Renamed');
});

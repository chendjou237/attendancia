<?php

use App\Filament\Resources\TeacherBiometricIds\Pages\CreateTeacherBiometricId;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('creates a mapping through the admin panel', function () {
    $teacher = Teacher::factory()->create();

    Livewire::test(CreateTeacherBiometricId::class)
        ->fillForm(['teacher_id' => $teacher->id, 'biometric_id' => '42', 'valid_from' => '2026-09-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    $mapping = TeacherBiometricId::first();
    expect($mapping->teacher_id)->toBe($teacher->id);
    expect($mapping->biometric_id)->toBe('42');
});

// The assigner's invariant applies through the UI too, not just direct
// service calls — creating a mapping here still closes out any prior
// holder of the same biometric_id.
it('closes out a previous holder when created through the admin panel', function () {
    $oldHolder = Teacher::factory()->create();
    $newHolder = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($oldHolder)->create(['biometric_id' => '99', 'valid_from' => '2026-01-01', 'valid_to' => null]);

    Livewire::test(CreateTeacherBiometricId::class)
        ->fillForm(['teacher_id' => $newHolder->id, 'biometric_id' => '99', 'valid_from' => '2026-09-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TeacherBiometricId::where('biometric_id', '99')->whereNull('valid_to')->count())->toBe(1);
    expect(TeacherBiometricId::where('biometric_id', '99')->whereNull('valid_to')->first()->teacher_id)->toBe($newHolder->id);
});

<?php

use App\Filament\Resources\CalendarDays\Pages\CreateCalendarDay;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

// Regression test: $get('day_type') resolves to a DayType enum instance
// (via the Select's enum options()), not a raw string. A comparison
// against DayType::HalfDay->value is an object-vs-string === that is
// always false, which silently hid both conditional fields no matter
// what day_type was selected.
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('shows the half-day cutoff field only when day_type is half_day', function () {
    Livewire::test(CreateCalendarDay::class)
        ->fillForm(['day_type' => 'half_day'])
        ->assertFormFieldIsVisible('half_day_cutoff_slot_id')
        ->assertFormFieldIsHidden('suspendedClassCodes');
});

it('shows the suspended-class-codes field only when day_type is classes_suspended', function () {
    Livewire::test(CreateCalendarDay::class)
        ->fillForm(['day_type' => 'classes_suspended'])
        ->assertFormFieldIsHidden('half_day_cutoff_slot_id')
        ->assertFormFieldIsVisible('suspendedClassCodes');
});

it('hides both conditional fields for an ordinary teaching day', function () {
    Livewire::test(CreateCalendarDay::class)
        ->fillForm(['day_type' => 'teaching'])
        ->assertFormFieldIsHidden('half_day_cutoff_slot_id')
        ->assertFormFieldIsHidden('suspendedClassCodes');
});

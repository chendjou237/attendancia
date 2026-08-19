<?php

use App\Filament\Resources\PeriodSlots\Pages\CreatePeriodSlot;
use App\Filament\Resources\PeriodSlots\Pages\EditPeriodSlot;
use App\Filament\Resources\PeriodSlots\Pages\ListPeriodSlots;
use App\Models\PeriodSlot;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

it('creates a period slot through the admin panel', function () {
    Livewire::test(CreatePeriodSlot::class)
        ->fillForm([
            'day_of_week' => 1,
            'seq' => 1,
            'start_time' => '07:30:00',
            'end_time' => '08:25:00',
            'is_break' => false,
            'valid_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $slot = PeriodSlot::first();
    expect($slot->day_of_week)->toBe(1);
    expect($slot->seq)->toBe(1);
    expect($slot->start_time)->toBe('07:30:00');
    expect($slot->end_time)->toBe('08:25:00');
    expect($slot->is_break)->toBeFalse();
    expect($slot->valid_from->toDateString())->toBe('2026-09-01');
});

it('rejects an end_time that is not after start_time', function () {
    Livewire::test(CreatePeriodSlot::class)
        ->fillForm([
            'day_of_week' => 1,
            'seq' => 1,
            'start_time' => '08:25:00',
            'end_time' => '07:30:00',
            'valid_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['end_time']);

    expect(PeriodSlot::count())->toBe(0);
});

it('edits an existing period slot through the admin panel', function () {
    $slot = PeriodSlot::factory()->create([
        'day_of_week' => 2,
        'seq' => 3,
        'start_time' => '09:20:00',
        'end_time' => '10:15:00',
        'valid_from' => '2026-01-01',
    ]);

    Livewire::test(EditPeriodSlot::class, ['record' => $slot->getKey()])
        ->fillForm(['end_time' => '10:20:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($slot->refresh()->end_time)->toBe('10:20:00');
});

it('lists only the currently active version by default', function () {
    $current = PeriodSlot::factory()->create([
        'day_of_week' => 1,
        'seq' => 1,
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => null,
    ]);

    $superseded = PeriodSlot::factory()->create([
        'day_of_week' => 1,
        'seq' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => now()->subMonth()->subDay()->toDateString(),
    ]);

    Livewire::test(ListPeriodSlots::class)
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$superseded]);
});

it('shows every version once the current-only filter is removed', function () {
    $current = PeriodSlot::factory()->create([
        'day_of_week' => 1,
        'seq' => 1,
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => null,
    ]);

    $superseded = PeriodSlot::factory()->create([
        'day_of_week' => 1,
        'seq' => 1,
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => now()->subMonth()->subDay()->toDateString(),
    ]);

    Livewire::test(ListPeriodSlots::class)
        ->filterTable('current_only', false)
        ->assertCanSeeTableRecords([$current, $superseded]);
});

it('sorts the default list by day of week then sequence, matching the bell-schedule grid', function () {
    $mondayTwo = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 2]);
    $mondayOne = PeriodSlot::factory()->create(['day_of_week' => 1, 'seq' => 1]);
    $tuesdayOne = PeriodSlot::factory()->create(['day_of_week' => 2, 'seq' => 1]);

    Livewire::test(ListPeriodSlots::class)
        ->assertCanSeeTableRecords([$mondayOne, $mondayTwo, $tuesdayOne], inOrder: true);
});

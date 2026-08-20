<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Livewire::test() never runs the HTTP kernel, so it never runs
 * SetLocale — these must hit a real route via $this->get(...) to prove
 * anything about the middleware actually being wired into the panel.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('applies a user\'s own locale to admin panel requests', function () {
    $user = User::factory()->create(['locale' => 'fr']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Tableau de bord');
});

it('falls back to config app.locale when the user has none set and no session value exists', function () {
    $user = User::factory()->create(['locale' => null]);
    $user->assignRole('admin');

    config(['app.locale' => 'fr']);

    $this->actingAs($user)->get('/admin');

    expect(app()->getLocale())->toBe('fr');
});

it('prefers a session locale over config, but a set User::locale still wins over both', function () {
    $user = User::factory()->create(['locale' => null]);
    $user->assignRole('admin');
    config(['app.locale' => 'en']);

    $this->actingAs($user)
        ->withSession(['locale' => 'fr'])
        ->get('/admin');
    expect(app()->getLocale())->toBe('fr');

    $user->update(['locale' => 'en']);

    $this->actingAs($user->fresh())
        ->withSession(['locale' => 'fr'])
        ->get('/admin');
    expect(app()->getLocale())->toBe('en');
});

it('the locale switcher writes to the session and a following request picks it up', function () {
    $user = User::factory()->create(['locale' => null]);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/locale/fr')->assertRedirect();
    expect(session('locale'))->toBe('fr');

    $this->actingAs($user)->get('/admin');
    expect(app()->getLocale())->toBe('fr');
});

it('rejects an unsupported locale on the switcher route', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)->get('/locale/de')->assertNotFound();
});

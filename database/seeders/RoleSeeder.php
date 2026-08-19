<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * §10's four roles: Admin, Officer, Principal, HR. Permissions within
 * each role are assigned per-resource as Filament resources are built,
 * not enumerated here — this seeder only guarantees the roles exist so
 * they can be assigned to users and referenced by panel access checks.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'officer', 'principal', 'hr'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}

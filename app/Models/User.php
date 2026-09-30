<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Contracts\GuardsDeletion;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, GuardsDeletion
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * §10: only the four defined roles ever reach the admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['admin', 'officer', 'principal', 'hr']);
    }

    /**
     * Deleting your own login, or the last admin, leaves nobody able to
     * sign in and create accounts — recovery then needs a shell on the
     * server. Both have happened.
     */
    public function deletionBlockedReason(): ?string
    {
        if ($this->is(Auth::user())) {
            return __('panel.resources.users.cannot_delete_self');
        }

        if ($this->hasRole('admin') && static::role('admin')->count() <= 1) {
            return __('panel.resources.users.cannot_delete_last_admin');
        }

        return null;
    }

    protected static function booted(): void
    {
        // Roles are already detached by the time this fires (see
        // App\Filament\Actions\GuardedDelete), so only the identity is kept.
        static::deleted(fn (User $user) => AuditLog::record(
            entity: 'users',
            entityId: $user->id,
            action: 'delete',
            before: $user->only(['name', 'email']),
            after: null,
        ));
    }
}

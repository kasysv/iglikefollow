<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';

    public const ROLE_EDITOR = 'editor';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_EDITOR];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Panel access requires BOTH an allowed role and an active account.
     *
     * A plain User row must never gain admin access implicitly, even locally.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active === true
            && in_array($this->role, self::ROLES, true);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER && $this->is_active === true;
    }

    /**
     * True when this record is the only active owner left.
     *
     * Removing or demoting that account would leave nobody able to publish or
     * manage users, so callers must refuse the change.
     */
    public function isLastActiveOwner(): bool
    {
        if (! $this->isOwner()) {
            return false;
        }

        return static::query()
            ->where('role', self::ROLE_OWNER)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    public function isEditor(): bool
    {
        return $this->role === self::ROLE_EDITOR && $this->is_active === true;
    }
}

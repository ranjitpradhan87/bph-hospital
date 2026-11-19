<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    /** New accounts are active; the column default is mirrored here so a freshly created model is usable at once. */
    protected $attributes = ['is_active' => true];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->with('permissions');
    }

    /** @return list<string> */
    public function roleCodes(): array
    {
        return $this->roles->pluck('code')->sort()->values()->all();
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return $this->roles->flatMap(fn (Role $r) => $r->permissions->pluck('code'))->unique()->sort()->values()->all();
    }

    public function can($abilities, $arguments = []): bool
    {
        return in_array($abilities, $this->permissionCodes(), true);
    }

    public function hasAllPermissions(array $codes): bool
    {
        return array_diff($codes, $this->permissionCodes()) === [];
    }
}

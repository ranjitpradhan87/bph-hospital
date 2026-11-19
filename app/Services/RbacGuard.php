<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Privilege guardrails shared by user, staff and doctor administration. */
final class RbacGuard
{
    /** @param list<string> $codes @return Collection<int, Role> */
    public static function resolveRoles(array $codes, User $actor): Collection
    {
        $wanted = array_values(array_unique($codes));
        if ($wanted === []) {
            abort(400, 'At least one role is required');
        }
        if (in_array('patient', $wanted, true)) {
            abort(400, 'Patient accounts are created through patient registration');
        }
        if (array_intersect($wanted, Permissions::PRIVILEGED_ROLES) && ! $actor->can('roles.manage')) {
            abort(403, 'Only a super admin can grant administrator roles');
        }
        $roles = Role::with('permissions')->whereIn('code', $wanted)->get();
        $unknown = array_diff($wanted, $roles->pluck('code')->all());
        if ($unknown) {
            abort(400, 'Unknown roles: '.implode(', ', $unknown));
        }
        // No escalation through custom roles: you can only grant permissions you hold yourself.
        $granted = $roles->flatMap(fn (Role $r) => $r->permissions->pluck('code'))->unique()->all();
        if (array_diff($granted, $actor->permissionCodes())) {
            abort(403, 'You cannot grant a role with permissions you do not have');
        }

        return $roles;
    }

    public static function guardTarget(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            abort(403, 'You cannot change your own roles or status');
        }
        if (array_intersect($target->roleCodes(), Permissions::PRIVILEGED_ROLES) && ! $actor->can('roles.manage')) {
            abort(403, 'Only a super admin can modify administrator accounts');
        }
        if (array_diff($target->permissionCodes(), $actor->permissionCodes())) {
            abort(403, 'You cannot modify a user with permissions you do not have');
        }
    }

    public static function activeSuperAdmins(): int
    {
        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'super_admin'))->count();
    }

    public static function ensureContactAvailable(?string $email, ?string $mobile, ?int $exceptUserId = null): void
    {
        foreach ([['email', $email, 'email'], ['mobile', $mobile, 'mobile number']] as [$column, $value, $label]) {
            if (! $value) {
                continue;
            }
            $q = User::withTrashed()->where($column, $value);
            if ($exceptUserId) {
                $q->where('id', '!=', $exceptUserId);
            }
            if ($q->exists()) {
                throw new HttpException(409, "An account with this {$label} already exists");
            }
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    private static function out(Role $r): array
    {
        $r->loadMissing('permissions')->loadCount('users');

        return [
            'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description,
            'is_system' => $r->is_system, 'permissions' => $r->permissions->pluck('code')->sort()->values()->all(),
            'user_count' => $r->users_count,
        ];
    }

    /** @param list<string> $codes @return list<int> */
    private static function permissionIds(array $codes): array
    {
        $found = Permission::whereIn('code', $codes)->pluck('id', 'code');
        $unknown = array_diff($codes, $found->keys()->all());
        if ($unknown) {
            abort(400, 'Unknown permissions: '.implode(', ', $unknown));
        }

        return $found->values()->all();
    }

    private static function custom(Role $role): void
    {
        if ($role->is_system) {
            abort(400, 'System roles are managed by the application and cannot be changed');
        }
    }

    public function permissions(): JsonResponse
    {
        return response()->json(Permission::orderBy('module')->orderBy('code')->get(['id', 'code', 'module', 'description']));
    }

    public function index(): JsonResponse
    {
        return response()->json(Role::with('permissions')->withCount('users')->orderByDesc('is_system')->orderBy('name')->get()->map(fn ($r) => self::out($r)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[a-z][a-z0-9_]{2,39}$/'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'], 'permissions.*' => ['string'],
        ]);
        if (Role::where('code', $data['code'])->exists()) {
            abort(409, 'A role with this code already exists');
        }
        $role = Role::create(['code' => $data['code'], 'name' => trim($data['name']), 'description' => $data['description'] ?? '', 'is_system' => false]);
        $role->permissions()->sync(self::permissionIds($data['permissions'] ?? []));
        Audit::record('create', 'role', $role->id, ['code' => $role->code, 'permissions' => $data['permissions'] ?? []]);

        return response()->json(self::out($role), 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(self::out($role));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        self::custom($role);
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:80'], 'description' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $role->update(array_map(fn ($v) => $v ?? '', $data));
        Audit::record('update', 'role', $role->id, array_keys($data));

        return response()->json(self::out($role));
    }

    public function setPermissions(Request $request, Role $role): JsonResponse
    {
        self::custom($role);
        $codes = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string']])['permissions'];
        $before = $role->permissions()->pluck('code')->sort()->values()->all();
        $role->permissions()->sync(self::permissionIds($codes));
        Audit::record('update_permissions', 'role', $role->id, ['before' => $before, 'after' => collect($codes)->unique()->sort()->values()->all()]);

        return response()->json(self::out($role->refresh()));
    }

    public function destroy(Role $role): JsonResponse
    {
        self::custom($role);
        if ($role->users()->exists()) {
            abort(409, 'Remove this role from all users before deleting it');
        }
        Audit::record('delete', 'role', $role->id, ['code' => $role->code]);
        $role->delete();

        return response()->json(null, 204);
    }
}

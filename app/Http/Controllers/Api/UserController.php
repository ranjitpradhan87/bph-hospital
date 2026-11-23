<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RbacGuard;
use App\Services\StaffAccountMail;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public static function out(User $u): array
    {
        return [
            'id' => $u->id, 'full_name' => $u->full_name, 'email' => $u->email, 'mobile' => $u->mobile,
            'is_active' => $u->is_active, 'roles' => $u->roleCodes(), 'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => 'integer|min:1', 'page_size' => 'integer|min:1|max:100', 'search' => 'nullable|string|max:100']);
        $role = $request->query('role');
        $q = User::with('roles.permissions');
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('mobile', 'like', "%{$s}%"));
        }
        if ($role) {
            $q->whereHas('roles', fn ($r) => $r->where('code', $role));
        }
        if ($role !== 'patient') {
            // Staff directory: patient-only accounts are managed from the patients module.
            $q->whereHas('roles', fn ($r) => $r->where('code', '!=', 'patient'));
        }
        if ($request->query('is_active') !== null) {
            $q->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        $size = (int) $request->query('page_size', 20);
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($size, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'items' => $page->getCollection()->map(fn ($u) => self::out($u))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $size,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['mobile' => Phone::normalize((string) $request->input('mobile', '')), 'email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['required', Password::min(8)->max(128)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string'],
        ], ['mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);
        $actor = $request->user();
        RbacGuard::ensureContactAvailable($data['email'], $data['mobile']);
        $roles = RbacGuard::resolveRoles($data['roles'], $actor);

        $user = DB::transaction(function () use ($data, $roles) {
            $user = User::create(['email' => $data['email'], 'mobile' => $data['mobile'], 'full_name' => trim($data['full_name']), 'password' => $data['password']]);
            $user->roles()->sync($roles->pluck('id'));
            Audit::record('create', 'user', $user->id, ['roles' => $roles->pluck('code')->sort()->values()->all()]);

            return $user;
        });

        $sent = StaffAccountMail::welcome($user, $data['password']);

        return response()->json(self::out($user->load('roles.permissions')) + ['email_sent' => $sent], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(self::out($user->load('roles.permissions')));
    }

    public function setRoles(Request $request, User $user): JsonResponse
    {
        $codes = $request->validate(['roles' => ['required', 'array'], 'roles.*' => ['string']])['roles'];
        $actor = $request->user();
        RbacGuard::guardTarget($user, $actor);
        if (in_array('patient', $user->roleCodes(), true)) {
            abort(400, 'Patient account roles cannot be changed here');
        }
        $roles = RbacGuard::resolveRoles($codes, $actor);
        $before = $user->roleCodes();
        if (in_array('super_admin', $before, true) && ! $roles->contains('code', 'super_admin') && $user->is_active && RbacGuard::activeSuperAdmins() <= 1) {
            abort(409, 'At least one active super admin must remain');
        }
        $user->roles()->sync($roles->pluck('id'));
        Audit::record('update_roles', 'user', $user->id, ['before' => $before, 'after' => $roles->pluck('code')->sort()->values()->all()]);

        return response()->json(self::out($user->load('roles.permissions')));
    }

    public function setStatus(Request $request, User $user): JsonResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        RbacGuard::guardTarget($user, $request->user());
        if (! $active && in_array('super_admin', $user->roleCodes(), true) && RbacGuard::activeSuperAdmins() <= 1) {
            abort(409, 'At least one active super admin must remain');
        }
        $user->update(['is_active' => $active]);
        if (! $active) {
            DB::table('sessions')->where('user_id', $user->id)->delete(); // sign them out everywhere
        }
        Audit::record($active ? 'activate' : 'deactivate', 'user', $user->id);

        return response()->json(self::out($user->load('roles.permissions')));
    }
}

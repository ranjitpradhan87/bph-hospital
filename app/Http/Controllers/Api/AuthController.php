<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\SetupLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const MAX_FAILED = 5;

    private const LOCK_MINUTES = 15;

    public static function profile(User $user): array
    {
        $patient = Patient::where('user_id', $user->id)->first();

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'roles' => $user->roleCodes(),
            'permissions' => $user->permissionCodes(),
            'patient_id' => $patient?->id,
            'patient_code' => $patient?->patient_code,
            'doctor_id' => DB::table('doctors')->where('user_id', $user->id)->whereNull('deleted_at')->value('id'),
            'staff_id' => DB::table('staff')->where('user_id', $user->id)->whereNull('deleted_at')->value('id'),
        ];
    }

    private static ?string $dummy = null;

    private static function dummyHash(): string
    {
        return self::$dummy ??= Hash::make('dummy-password-for-timing');
    }

    private static function password(): Password
    {
        return Password::min(8)->max(128)->letters()->numbers();
    }

    public function register(Request $request): JsonResponse
    {
        $selfSignup = DB::table('hospital_settings')->where('key', 'registration.patient_self_signup')->value('value');
        if ($selfSignup !== null && json_decode($selfSignup) === false) {
            return response()->json(['detail' => 'Online registration is currently disabled. Please register at the hospital.'], 403);
        }

        $request->merge(['mobile' => Phone::normalize((string) $request->input('mobile', '')), 'email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:-130 years'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'address' => ['nullable', 'string', 'max:500'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'password' => ['required', self::password()],
            'consent' => ['accepted'],
        ], [
            'mobile.regex' => 'Enter a valid 10-digit Indian mobile number',
            'consent.accepted' => 'Consent is required to create an account',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return response()->json(['detail' => 'An account with this email already exists'], 409);
        }
        if (User::where('mobile', $data['mobile'])->exists()) {
            return response()->json(['detail' => 'An account with this mobile number already exists'], 409);
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'], 'mobile' => $data['mobile'], 'full_name' => trim($data['full_name']),
                'password' => $data['password'], 'last_login_at' => now(),
            ]);
            $user->roles()->attach(Role::where('code', 'patient')->value('id'));

            // A walk-in registered at the desk (same mobile + DOB, no login yet) is linked, not duplicated.
            $patient = Patient::where('mobile', $data['mobile'])->whereDate('date_of_birth', $data['date_of_birth'])
                ->whereNull('user_id')->first();
            if ($patient) {
                $patient->update([
                    'user_id' => $user->id, 'consent_at' => now(), 'email' => $patient->email ?: $data['email'],
                    'blood_group' => $patient->blood_group ?: ($data['blood_group'] ?? null),
                    'address' => $patient->address ?: ($data['address'] ?? null),
                ]);
            } else {
                $patient = Patient::create([
                    'patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'user_id' => $user->id,
                    'full_name' => trim($data['full_name']), 'email' => $data['email'], 'mobile' => $data['mobile'],
                    'date_of_birth' => $data['date_of_birth'], 'gender' => $data['gender'],
                    'blood_group' => $data['blood_group'] ?? null, 'address' => $data['address'] ?? null,
                    'consent_at' => now(),
                ]);
                $patient->update(['patient_code' => Patient::codeFor($patient->id)]);
            }
            Audit::record('register', 'patient', $patient->id, ['linked_existing_record' => $patient->wasRecentlyCreated === false], $user->id);

            return $user;
        });

        return $this->signIn($request, $user, 201);
    }

    /** Choose a password from the link texted after booking without an account; signs the person in. */
    public function setPassword(Request $request): JsonResponse
    {
        $d = $request->validate(['u' => ['required', 'integer'], 'e' => ['required', 'integer'], 's' => ['required', 'string', 'max:128'], 'password' => ['required', self::password()]]);
        $user = SetupLink::verify((int) $d['u'], (int) $d['e'], $d['s']);
        if (! $user) {
            return response()->json(['detail' => 'This link has expired or was already used. Use "Forgot password" or call the hospital.'], 410);
        }
        $user->update(['password' => $d['password'], 'password_changed_at' => now(), 'failed_login_count' => 0, 'locked_until' => null]);
        Audit::record('password_set', 'user', $user->id, ['via' => 'text_link'], $user->id);

        return $this->signIn($request, $user);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'min:3', 'max:255'], 'password' => ['required', 'string', 'max:128']]);
        $ident = trim($data['identifier']);
        $user = str_contains($ident, '@')
            ? User::where('email', strtolower($ident))->first()
            : (Phone::normalize($ident) !== '' ? User::where('mobile', Phone::normalize($ident))->first() : null);

        if (! $user) {
            Hash::check($data['password'], self::dummyHash()); // equalise timing with a real check
            Audit::record('login_failed', 'user', null, ['reason' => 'unknown_user']);

            return response()->json(['detail' => 'Invalid credentials'], 401);
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return response()->json(['detail' => 'Too many failed attempts. Your account is locked for a few minutes.'], 423);
        }
        if (! Hash::check($data['password'], $user->password)) {
            $user->failed_login_count++;
            $reason = 'bad_password';
            if ($user->failed_login_count >= self::MAX_FAILED) {
                $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
                $user->failed_login_count = 0;
                $reason = 'locked';
            }
            $user->save();
            Audit::record('login_failed', 'user', $user->id, ['reason' => $reason], $user->id);

            return response()->json(['detail' => 'Invalid credentials'], 401);
        }
        if (! $user->is_active) {
            return response()->json(['detail' => 'This account has been deactivated. Please contact the hospital.'], 403);
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        Audit::record('login', 'user', $user->id, null, $user->id);

        return $this->signIn($request, $user);
    }

    private function signIn(Request $request, User $user, int $status = 200): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => self::profile($user->fresh())], $status);
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::record('logout', 'user', Auth::id());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::profile($request->user()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', self::password()]]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['detail' => 'Current password is incorrect'], 401);
        }
        if (Hash::check($data['new_password'], $user->password)) {
            return response()->json(['detail' => 'New password must be different from the current password'], 409);
        }
        $user->forceFill(['password' => $data['new_password'], 'password_changed_at' => now()])->save();
        // End every other session for this user (the session driver is the database).
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::record('password_change', 'user', $user->id);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\Audit;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /** Appointment statuses that still hold a slot. */
    public const ACTIVE_STATUSES = ['pending', 'confirmed', 'checked_in', 'in_consultation'];

    public static function slugify(string $name): string
    {
        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 120) ?: 'department';
    }

    /** @return array{0: array<int,int>, 1: array<int,int>} doctor and staff counts by department id */
    private static function counts(): array
    {
        $doctors = DB::table('doctor_departments as dd')->join('doctors as d', 'd.id', '=', 'dd.doctor_id')
            ->whereNull('d.deleted_at')->groupBy('dd.department_id')->selectRaw('dd.department_id as id, count(*) as c')->pluck('c', 'id')->all();
        $staff = DB::table('staff')->whereNull('deleted_at')->whereNotNull('department_id')
            ->groupBy('department_id')->selectRaw('department_id as id, count(*) as c')->pluck('c', 'id')->all();

        return [$doctors, $staff];
    }

    private static function out(Department $d, array $counts): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'slug' => $d->slug, 'short_description' => $d->short_description,
            'description' => $d->description, 'icon' => $d->icon, 'image_url' => $d->image_url,
            'display_order' => $d->display_order, 'is_active' => $d->is_active,
            'doctor_count' => (int) ($counts[0][$d->id] ?? 0), 'staff_count' => (int) ($counts[1][$d->id] ?? 0),
        ];
    }

    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', Rule::in(SettingsRegistry::ICONS)],
            'image_url' => ['nullable', 'string', SettingsRegistry::imageUrlRule()],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private static function ensureUnique(string $name, string $slug, ?int $exceptId = null): void
    {
        foreach ([['name', $name, 'name'], ['slug', $slug, 'web address']] as [$col, $val, $label]) {
            $q = Department::withTrashed()->whereRaw("lower($col) = ?", [mb_strtolower($val)]);
            if ($exceptId) {
                $q->where('id', '!=', $exceptId);
            }
            if ($q->exists()) {
                abort(409, "Another department already uses this $label");
            }
        }
    }

    private static function fields(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = Department::orderBy('display_order')->orderBy('name');
        if (! $request->boolean('include_inactive', true)) {
            $q->where('is_active', true);
        }
        $counts = self::counts();

        return response()->json($q->get()->map(fn ($d) => self::out($d, $counts)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::rules());
        $slug = $data['slug'] ?? self::slugify($data['name']);
        self::ensureUnique($data['name'], $slug);
        $dept = Department::create(self::fields($data) + ['slug' => $slug]);
        Audit::record('create', 'department', $dept->id, ['name' => $dept->name]);

        return response()->json(self::out($dept->refresh(), self::counts()), 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate(self::rules());
        $slug = $data['slug'] ?? $department->slug;
        self::ensureUnique($data['name'], $slug, $department->id);
        $department->fill(self::fields($data) + ['slug' => $slug]);
        $changes = [];
        foreach ($department->getDirty() as $k => $v) {
            $changes[$k] = ['before' => $department->getOriginal($k), 'after' => $v];
        }
        if ($changes) {
            $department->save();
            Audit::record('update', 'department', $department->id, $changes);
        }

        return response()->json(self::out($department->refresh(), self::counts()));
    }

    public function setStatus(Request $request, Department $department): JsonResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $department->update(['is_active' => $active]);
        Audit::record($active ? 'activate' : 'deactivate', 'department', $department->id);

        return response()->json(self::out($department->refresh(), self::counts()));
    }

    /** Soft delete, only when nothing still depends on the department. */
    public function destroy(Department $department): JsonResponse
    {
        [$doctors, $staff] = array_map(fn ($c) => (int) ($c[$department->id] ?? 0), self::counts());
        if ($doctors || $staff) {
            abort(409, "Move its $doctors doctor(s) and $staff staff member(s) to another department first");
        }
        $upcoming = DB::table('appointments')->where('department_id', $department->id)
            ->where('appointment_date', '>=', now(config('app.hospital_timezone', 'Asia/Kolkata'))->toDateString())
            ->whereIn('status', self::ACTIVE_STATUSES)->count();
        if ($upcoming) {
            abort(409, "$upcoming upcoming appointment(s) are booked in this department");
        }
        // Free the name and slug for reuse while keeping the row for history.
        $department->forceFill([
            'is_active' => false,
            'name' => substr("{$department->name} (deleted {$department->id})", 0, 100),
            'slug' => substr("{$department->slug}-deleted-{$department->id}", 0, 120),
        ])->save();
        $department->delete();
        Audit::record('delete', 'department', $department->id);

        return response()->json(null, 204);
    }
}

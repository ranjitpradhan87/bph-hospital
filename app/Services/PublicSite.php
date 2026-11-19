<?php

namespace App\Services;

use App\Http\Controllers\Api\DoctorController;
use App\Support\Hospital;
use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the public website. Used by both the JSON API and the server-rendered pages.
 * Nothing here may return patient data or staff contact details.
 */
final class PublicSite
{
    /** @return array{settings: array<string, array<string, mixed>>, stats: array<string, int>} */
    public static function settings(): array
    {
        $nested = [];
        foreach (DB::table('hospital_settings')->where('is_public', true)->get(['key', 'value']) as $row) {
            [$group, $name] = array_pad(explode('.', $row->key, 2), 2, '');
            $nested[$group][$name] = json_decode($row->value, true);
        }

        return [
            'settings' => $nested,
            'stats' => [
                'departments' => DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->count(),
                'doctors' => self::bookableDoctors()->count(),
            ],
        ];
    }

    private static function bookableDoctors()
    {
        return DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.status', 'active')->whereNull('d.deleted_at')->where('u.is_active', true)->whereNull('u.deleted_at');
    }

    /** @return list<array<string, mixed>> */
    public static function departments(): array
    {
        $counts = DB::table('doctor_departments as dd')->join('doctors as d', 'd.id', '=', 'dd.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.status', 'active')->whereNull('d.deleted_at')->where('u.is_active', true)->whereNull('u.deleted_at')
            ->groupBy('dd.department_id')->selectRaw('dd.department_id as id, count(*) as c')->pluck('c', 'id');

        return DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->orderBy('display_order')->orderBy('name')->get()
            ->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'slug' => $d->slug, 'short_description' => $d->short_description,
                'description' => $d->description, 'image_url' => $d->image_url, 'icon' => $d->icon, 'doctor_count' => (int) ($counts[$d->id] ?? 0),
            ])->all();
    }

    public static function department(string $slug): ?array
    {
        return collect(self::departments())->firstWhere('slug', $slug);
    }

    /** @return list<string> */
    public static function specializations(): array
    {
        return self::bookableDoctors()->distinct()->orderBy('d.specialization')->pluck('d.specialization')->all();
    }

    /** @param list<int> $ids @return array<int, array{0: string, 1: int}> */
    private static function availability(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = Hospital::bookableSlots()->whereIn('s.doctor_id', $ids)->groupBy('s.doctor_id')->selectRaw('s.doctor_id as id, min(s.slot_date) as first, count(*) as c')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->id => [$r->first, (int) $r->c]])->all();
    }

    private static function doctorOut(object $d, array $avail, array $depts): array
    {
        [$first, $count] = $avail[$d->id] ?? [null, 0];

        return [
            'id' => $d->id, 'full_name' => $d->full_name, 'qualification' => $d->qualification, 'specialization' => $d->specialization,
            'experience_years' => (int) $d->experience_years, 'consultation_fee' => DoctorController::money($d->consultation_fee),
            'advance_amount' => SettingsRegistry::value('booking.advance_required', true) ? BookingService::advanceFor((float) $d->consultation_fee, (int) $d->id) : 0,
            'photo_url' => $d->photo_url, 'languages' => $d->languages, 'departments' => $depts[$d->id] ?? [],
            'next_available_date' => $first, 'available_slot_count' => $count,
        ];
    }

    /** @param list<int> $ids @return array<int, list<array<string, mixed>>> only active, non-deleted departments */
    private static function departmentRefs(array $ids): array
    {
        $rows = DB::table('doctor_departments as dd')->join('departments as dep', 'dep.id', '=', 'dd.department_id')
            ->whereIn('dd.doctor_id', $ids)->where('dep.is_active', true)->whereNull('dep.deleted_at')->orderBy('dep.name')
            ->get(['dd.doctor_id', 'dep.id', 'dep.name', 'dep.slug']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->doctor_id][] = ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug];
        }

        return $out;
    }

    /** @param array{search?: ?string, department?: ?string, specialization?: ?string, available_on?: ?string, featured?: ?bool, page?: int, page_size?: int} $f */
    public static function doctors(array $f): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        $size = min(50, max(1, (int) ($f['page_size'] ?? 12)));
        $q = self::bookableDoctors();
        if (! empty($f['search'])) {
            $like = '%'.trim($f['search']).'%';
            $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('d.specialization', 'like', $like)->orWhere('d.qualification', 'like', $like));
        }
        if (! empty($f['department'])) {
            $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('doctor_departments as dd')->join('departments as dep', 'dep.id', '=', 'dd.department_id')
                ->whereColumn('dd.doctor_id', 'd.id')->where('dep.slug', $f['department']));
        }
        if (! empty($f['specialization'])) {
            $q->where('d.specialization', $f['specialization']);
        }
        if (! empty($f['featured'])) {
            $q->where('d.is_featured', true);
        }
        if (! empty($f['available_on'])) {
            $q->whereIn('d.id', Hospital::bookableSlots($f['available_on'], $f['available_on'])->select('s.doctor_id'));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('d.is_featured')->orderBy('u.full_name')->offset(($page - 1) * $size)->limit($size)
            ->get(['d.*', 'u.full_name']);
        $ids = $rows->pluck('id')->all();
        $avail = self::availability($ids);
        $depts = self::departmentRefs($ids);

        return ['items' => $rows->map(fn ($d) => self::doctorOut($d, $avail, $depts))->all(), 'total' => $total, 'page' => $page, 'page_size' => $size];
    }

    private static function bookableDoctor(int $id): ?object
    {
        return self::bookableDoctors()->where('d.id', $id)->first(['d.*', 'u.full_name']);
    }

    public static function doctor(int $id): ?array
    {
        $d = self::bookableDoctor($id);
        if (! $d) {
            return null;
        }
        $weekly = DB::table('doctor_schedules')->where('doctor_id', $id)->where('is_active', true)->orderBy('day_of_week')->orderBy('start_time')
            ->get()->map(fn ($s) => ['day_of_week' => (int) $s->day_of_week, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'appointment_type' => $s->appointment_type])->all();

        return self::doctorOut($d, self::availability([$id]), self::departmentRefs([$id])) + [
            'bio' => $d->bio, 'registration_number' => $d->registration_number, 'weekly_hours' => $weekly,
        ];
    }

    /** @return list<array<string, mixed>>|null null when the doctor isn't publicly bookable */
    public static function doctorSlots(int $id, string $on): ?array
    {
        BookingService::releaseExpiredHolds(); // times held for an unpaid advance count as free again
        if (! self::bookableDoctor($id)) {
            return null;
        }

        return Hospital::bookableSlots($on, $on)->where('s.doctor_id', $id)->orderBy('s.start_time')->get(['s.*'])->map(fn ($s) => [
            'id' => $s->id, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'remaining' => max(0, $s->capacity - $s->booked_count),
            'appointment_type' => $s->appointment_type, 'consultation_fee' => DoctorController::money($s->consultation_fee),
        ])->all();
    }

    public static function submitContact(array $d): void
    {
        if (! empty($d['website'])) {
            return; // honeypot tripped: pretend success, store nothing
        }
        DB::table('contact_messages')->insert([
            'name' => trim($d['name']), 'email' => strtolower($d['email']), 'phone' => $d['phone'] ?? null, 'subject' => $d['subject'] ?? null,
            'message' => trim($d['message']), 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

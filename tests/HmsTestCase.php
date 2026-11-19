<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Support\Hospital;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/** Shared setup: a fresh seeded database and helpers to create users by role. */
abstract class HmsTestCase extends TestCase
{
    use RefreshDatabase;

    protected const API = '/api/v1';

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SUPERADMIN_EMAIL' => 'root@example.com', 'SUPERADMIN_MOBILE' => '9000000001', 'SUPERADMIN_PASSWORD' => 'RootPass123'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        $this->seed(DatabaseSeeder::class);
        // Most tests book directly; the advance-payment tests switch this back on.
        $this->setSetting('booking.advance_required', false);
    }

    protected function next(): int
    {
        return ++self::$n;
    }

    protected function root(): User
    {
        return User::where('email', 'root@example.com')->firstOrFail();
    }

    protected function setSetting(string $key, mixed $value): void
    {
        DB::table('hospital_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'group' => explode('.', $key)[0], 'is_public' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function department(?string $name = null): array
    {
        $name ??= 'Dept '.$this->next();

        return $this->actingAs($this->root())->postJson(self::API.'/departments', ['name' => $name])->assertCreated()->json();
    }

    protected function doctorPayload(array $departmentIds, array $o = []): array
    {
        $n = $this->next();

        return $o + [
            'full_name' => "Dr Test $n", 'email' => "doc$n@example.com", 'mobile' => sprintf('93%08d', $n), 'password' => 'Doctor123',
            'gender' => 'male', 'qualification' => 'MBBS, MD', 'specialization' => 'Cardiology', 'experience_years' => 10,
            'registration_number' => "REG-$n", 'consultation_fee' => '600', 'department_ids' => $departmentIds,
        ];
    }

    protected function doctor(array $departmentIds, array $o = []): array
    {
        return $this->actingAs($this->root())->postJson(self::API.'/doctors', $this->doctorPayload($departmentIds, $o))->assertCreated()->json();
    }

    protected function patientRow(array $o = []): int
    {
        $n = $this->next();

        return DB::table('patients')->insertGetId($o + [
            'patient_code' => "TMP-$n", 'full_name' => "Patient $n", 'mobile' => sprintf('92%08d', $n), 'date_of_birth' => '1990-01-01',
            'gender' => 'female', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Insert a slot and an appointment directly (the booking flow itself is a later phase). */
    protected function appointment(array $doctor, int $patientId, int $daysAhead = 3, string $status = 'confirmed', string $start = '10:00:00'): int
    {
        $date = Hospital::now()->addDays($daysAhead)->toDateString();
        $dept = $doctor['departments'][0]['id'];
        $end = date('H:i:s', strtotime($start) + 900);
        $slot = DB::table('appointment_slots')->insertGetId([
            'doctor_id' => $doctor['id'], 'department_id' => $dept, 'slot_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'capacity' => 1, 'booked_count' => 1, 'consultation_fee' => 600, 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $active = in_array($status, ['pending', 'confirmed', 'checked_in', 'in_consultation'], true);

        return DB::table('appointments')->insertGetId([
            'appointment_number' => 'APT-T'.$this->next(), 'patient_id' => $patientId, 'doctor_id' => $doctor['id'], 'department_id' => $dept,
            'slot_id' => $slot, 'active_slot_key' => $active ? $slot : null, 'appointment_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'appointment_type' => 'in_person', 'status' => $status, 'consultation_fee' => 600, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function user(string $role, array $o = []): User
    {
        $n = $this->next();
        $u = User::create($o + ['full_name' => "$role $n", 'email' => "$role$n@example.com", 'mobile' => sprintf('97%08d', $n), 'password' => 'Secret123']);
        $u->refresh()->roles()->sync(Role::where('code', $role)->pluck('id'));

        return $u;
    }
}

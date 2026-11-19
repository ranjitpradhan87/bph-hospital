<?php

namespace Tests\Feature;

use App\Support\Hospital;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class DoctorStaffTest extends HmsTestCase
{
    private function staffPayload(array $o = []): array
    {
        $n = $this->next();

        return $o + ['full_name' => "Staff Member $n", 'email' => "member$n@example.com", 'mobile' => sprintf('94%08d', $n), 'password' => 'Staff1234',
            'roles' => ['receptionist'], 'designation' => 'Front office executive', 'joining_date' => '2025-04-01'];
    }

    public function test_create_and_update_doctor(): void
    {
        $root = $this->root();
        $a = $this->department();
        $b = $this->department();
        $doc = $this->doctor([$a['id'], $b['id']], ['primary_department_id' => $b['id']]);
        $this->assertStringStartsWith('DOC-', $doc['doctor_code']);
        $this->assertSame(['id' => $b['id'], 'name' => $b['name'], 'slug' => $b['slug'], 'is_primary' => true], $doc['departments'][0]);
        $this->assertSame('600.00', $doc['consultation_fee']);

        $login = $this->postJson(self::API.'/auth/login', ['identifier' => $doc['email'], 'password' => 'Doctor123'])->assertOk();
        $this->assertSame(['doctor'], $login->json('user.roles'));
        $this->assertSame($doc['id'], $login->json('user.doctor_id'));
        $this->app['auth']->forgetGuards();

        $this->actingAs($root)->postJson(self::API.'/doctors', $this->doctorPayload([$a['id']], ['registration_number' => strtolower($doc['registration_number'])]))->assertStatus(409);
        $this->actingAs($root)->postJson(self::API.'/doctors', $this->doctorPayload([$a['id']], ['email' => $doc['email']]))->assertStatus(409);
        $this->actingAs($root)->postJson(self::API.'/doctors', $this->doctorPayload([$a['id']], ['primary_department_id' => $b['id']]))->assertStatus(422);
        $this->actingAs($root)->postJson(self::API.'/doctors', $this->doctorPayload([9999]))->assertStatus(400);
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/doctors', $this->doctorPayload([$a['id']]))->assertStatus(403);

        $update = $this->doctorPayload([$a['id']], ['full_name' => 'Dr Renamed', 'email' => $doc['email'], 'mobile' => $doc['mobile'],
            'registration_number' => $doc['registration_number'], 'consultation_fee' => '900', 'is_featured' => true]);
        unset($update['password']);
        $body = $this->actingAs($root)->putJson(self::API."/doctors/{$doc['id']}", $update)->assertOk()->json();
        $this->assertSame('Dr Renamed', $body['full_name']);
        $this->assertSame('900.00', $body['consultation_fee']);
        $this->assertSame([$a['id']], array_column($body['departments'], 'id'));

        $this->actingAs($root)->getJson(self::API.'/doctors?search=Renamed')->assertJsonPath('total', 1);
    }

    public function test_doctor_deactivation_and_delete_safeguards(): void
    {
        $root = $this->root();
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $patient = $this->patientRow();
        $apt = $this->appointment($doc, $patient);
        $free = DB::table('appointment_slots')->insertGetId([
            'doctor_id' => $doc['id'], 'department_id' => $dept['id'], 'slot_date' => Hospital::now()->addDays(2)->toDateString(),
            'start_time' => '11:00:00', 'end_time' => '11:15:00', 'capacity' => 1, 'consultation_fee' => 650, 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($root)->patchJson(self::API."/doctors/{$doc['id']}/status", ['status' => 'inactive'])->assertStatus(409);
        $this->assertStringContainsString('1 upcoming', $res->json('detail'));
        $this->actingAs($root)->deleteJson(self::API."/doctors/{$doc['id']}")->assertStatus(409);
        $this->actingAs($root)->patchJson(self::API."/doctors/{$doc['id']}/status", ['status' => 'on_leave'])->assertOk();

        DB::table('appointments')->where('id', $apt)->update(['status' => 'cancelled', 'active_slot_key' => null]);
        $this->actingAs($root)->patchJson(self::API."/doctors/{$doc['id']}/status", ['status' => 'inactive'])->assertOk()->assertJsonPath('login_active', false);
        $this->assertSame('blocked', DB::table('appointment_slots')->where('id', $free)->value('status'));
        $this->postJson(self::API.'/auth/login', ['identifier' => $doc['email'], 'password' => 'Doctor123'])->assertStatus(403);

        $this->actingAs($root)->deleteJson(self::API."/doctors/{$doc['id']}")->assertStatus(204);
        $this->actingAs($root)->getJson(self::API."/doctors/{$doc['id']}")->assertStatus(404);
        // email and mobile are free for a new account
        $this->actingAs($root)->postJson(self::API.'/doctors', $this->doctorPayload([$dept['id']], ['email' => $doc['email'], 'mobile' => $doc['mobile']]))->assertCreated();
    }

    public function test_doctor_history_and_stats(): void
    {
        $root = $this->root();
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $pid = $this->patientRow();
        $this->appointment($doc, $pid, -2, 'completed', '09:00:00');
        $this->appointment($doc, $pid, -1, 'no_show', '09:00:00');
        $this->appointment($doc, $pid, 3, 'confirmed', '09:00:00');

        $history = $this->actingAs($root)->getJson(self::API."/doctors/{$doc['id']}/appointments")->assertOk();
        $this->assertSame(3, $history->json('total'));
        $stats = $this->actingAs($root)->getJson(self::API."/doctors/{$doc['id']}/stats")->assertOk()->json();
        $this->assertSame(1, $stats['by_status']['completed']);
        $this->assertSame(0.5, $stats['completion_rate']);
        $this->assertSame(1, $stats['unique_patients']);
        $this->assertSame(1, $stats['upcoming']);
    }

    public function test_staff_create_salary_privacy_and_employment(): void
    {
        $root = $this->root();
        $member = $this->actingAs($root)->postJson(self::API.'/staff', $this->staffPayload(['salary' => '32000']))->assertCreated()->json();
        $this->assertStringStartsWith('STF-', $member['staff_code']);
        $this->assertSame('32000.00', $member['salary']);
        $this->actingAs($root)->postJson(self::API.'/staff', $this->staffPayload(['roles' => ['doctor']]))->assertStatus(400);

        // Stored encrypted, never as plain text.
        $raw = DB::table('staff')->where('id', $member['id'])->value('salary_encrypted');
        $this->assertStringNotContainsString('32000', $raw);

        $this->actingAs($root)->postJson(self::API.'/roles', ['code' => 'hr_clerk', 'name' => 'HR Clerk', 'permissions' => ['staff.read', 'staff.manage', 'attendance.manage']])->assertCreated();
        $hr = $this->user('hr_clerk');
        $this->actingAs($hr)->getJson(self::API."/staff/{$member['id']}")->assertOk()->assertJsonPath('salary', null);
        $this->actingAs($hr)->postJson(self::API.'/staff', $this->staffPayload(['salary' => '1']))->assertStatus(403);

        foreach (DB::table('audit_logs')->where('entity_type', 'staff')->pluck('changes') as $c) {
            $this->assertStringNotContainsString('32000', (string) $c);
        }

        $this->actingAs($root)->patchJson(self::API."/staff/{$member['id']}/employment", ['employment_status' => 'resigned'])->assertOk()->assertJsonPath('login_active', false);
        $this->postJson(self::API.'/auth/login', ['identifier' => $member['email'], 'password' => 'Staff1234'])->assertStatus(403);
        $this->actingAs($root)->patchJson(self::API."/staff/{$member['id']}/employment", ['employment_status' => 'active'])->assertOk();
        $this->postJson(self::API.'/auth/login', ['identifier' => $member['email'], 'password' => 'Staff1234'])->assertOk();
    }

    public function test_shifts_and_attendance(): void
    {
        $root = $this->root();
        $member = $this->actingAs($root)->postJson(self::API.'/staff', $this->staffPayload(['roles' => ['nurse'], 'designation' => 'Staff nurse']))->assertCreated()->json();
        $day = Hospital::today();

        $shift = ['shift_date' => $day, 'start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => 'night'];
        $created = $this->actingAs($root)->postJson(self::API."/staff/{$member['id']}/shifts", $shift)->assertCreated();
        $this->actingAs($root)->postJson(self::API."/staff/{$member['id']}/shifts", $shift)->assertStatus(409);
        $listed = $this->actingAs($root)->getJson(self::API."/staff/{$member['id']}/shifts")->json();
        $this->assertSame(['night'], array_column($listed, 'shift_type'));
        $this->actingAs($root)->deleteJson(self::API.'/staff/shifts/'.$created->json('id'))->assertStatus(204);

        $rows = $this->actingAs($root)->getJson(self::API."/attendance?date=$day")->json();
        $mine = collect($rows)->firstWhere('staff_id', $member['id']);
        $this->assertNull($mine['status']);

        $body = ['attendance_date' => $day, 'entries' => [['staff_id' => $member['id'], 'status' => 'present', 'check_in' => '08:55', 'check_out' => '17:10']]];
        $mine = collect($this->actingAs($root)->putJson(self::API.'/attendance', $body)->assertOk()->json())->firstWhere('staff_id', $member['id']);
        $this->assertSame(['present', '08:55:00', '17:10:00'], [$mine['status'], $mine['check_in'], $mine['check_out']]);

        $body['entries'][0] = ['staff_id' => $member['id'], 'status' => 'half_day', 'check_out' => '13:00'] + $body['entries'][0];
        $this->actingAs($root)->putJson(self::API.'/attendance', $body)->assertOk(); // upsert, not a duplicate
        $month = $this->actingAs($root)->getJson(self::API."/staff/{$member['id']}/attendance?month=".substr($day, 0, 7))->json();
        $this->assertSame(['half_day'], array_column($month, 'status'));

        $future = ['attendance_date' => Hospital::now()->addDay()->toDateString(), 'entries' => [['staff_id' => $member['id'], 'status' => 'present']]];
        $this->actingAs($root)->putJson(self::API.'/attendance', $future)->assertStatus(400);
        $bad = ['attendance_date' => $day, 'entries' => [['staff_id' => $member['id'], 'status' => 'present', 'check_in' => '10:00', 'check_out' => '09:00']]];
        $this->actingAs($root)->putJson(self::API.'/attendance', $bad)->assertStatus(422);
    }
}

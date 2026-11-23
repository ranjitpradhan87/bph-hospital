<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Hospital;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class OpdTest extends HmsTestCase
{
    private function walkIn(array $d, array $o = [], string $as = 'receptionist'): array
    {
        return $this->actingAs($this->user($as))->postJson(self::API.'/opd/visits', $o + ['doctor_id' => $d['id'], 'patient_name' => 'Walk In Patient', 'patient_mobile' => '9876543210'])->assertCreated()->json();
    }

    private function doctorUser(array $d): User
    {
        return User::findOrFail(DB::table('doctors')->where('id', $d['id'])->value('user_id'));
    }

    public function test_access_is_limited(): void
    {
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/opd/visits')->assertOk();
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/opd/visits')->assertOk();
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/opd/visits', [])->assertStatus(403);
        $this->actingAs($this->user('lab_technician'))->getJson(self::API.'/opd/visits')->assertStatus(403);
        $this->actingAs($this->user('patient'))->getJson(self::API.'/opd/visits')->assertStatus(403);
    }

    public function test_a_walk_in_is_registered_given_a_token_and_billed(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $v = $this->walkIn($d, ['payment' => ['amount' => 400, 'method' => 'cash']]);
        $this->assertSame(1, $v['token_number']);
        $this->assertSame('walk_in', $v['source']);
        $this->assertSame('waiting', $v['status']);
        $this->assertEquals([600, 600, 400, 200], [$v['fee'], $v['bill']['total'], $v['bill']['paid'], $v['bill']['balance']]);
        $this->assertDatabaseHas('patients', ['id' => $v['patient_id'], 'full_name' => 'Walk In Patient']);
        $this->assertCount(1, $v['payments']);

        $second = $this->walkIn($d, ['patient_id' => $this->patientRow(), 'discount' => 100]);
        $this->assertSame(2, $second['token_number']);
        $this->assertEquals(500, $second['bill']['total']);

        // another doctor has their own tokens
        $other = $this->doctor([$this->department()['id']]);
        $this->assertSame(1, $this->walkIn($other)['token_number']);

        // the balance is collected later, and cannot be overpaid
        $rc = $this->user('receptionist');
        $this->actingAs($rc)->postJson(self::API."/opd/visits/{$v['id']}/payments", ['amount' => 300, 'method' => 'cash'])->assertStatus(422);
        $this->actingAs($rc)->postJson(self::API."/opd/visits/{$v['id']}/payments", ['amount' => 200, 'method' => 'upi'])->assertCreated()->assertJsonPath('bill.status', 'paid');
        $this->assertSame('paid', DB::table('bills')->where('id', $v['bill']['id'])->value('status'));
    }

    public function test_bad_registrations_are_refused(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $rc = $this->user('receptionist');
        $this->actingAs($rc)->postJson(self::API.'/opd/visits', ['patient_name' => 'No Doctor'])->assertStatus(422);
        $this->actingAs($rc)->postJson(self::API.'/opd/visits', ['doctor_id' => $d['id']])->assertStatus(422);
        $this->actingAs($rc)->postJson(self::API.'/opd/visits', ['doctor_id' => $d['id'], 'patient_name' => 'X Y', 'discount' => 700])->assertStatus(422);
        $this->actingAs($rc)->postJson(self::API.'/opd/visits', ['doctor_id' => 99999, 'patient_name' => 'X Y'])->assertStatus(400);
    }

    public function test_a_booked_appointment_becomes_a_visit_once_and_counts_its_advance(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $pid = $this->patientRow();
        $appt = $this->appointment($d, $pid, 0);
        DB::table('appointments')->where('id', $appt)->update(['payment_status' => 'advance_paid', 'advance_amount' => 300, 'payment_reference' => 'pay_1', 'appointment_date' => now()->toDateString()]);

        $v = $this->walkIn($d, ['appointment_id' => $appt]);
        $this->assertSame('appointment', $v['source']);
        $this->assertSame($pid, $v['patient_id']);
        $this->assertEquals([600, 300], [$v['bill']['total'], $v['bill']['paid']]);
        $this->assertSame('checked_in', DB::table('appointments')->where('id', $appt)->value('status'));
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/opd/visits', ['appointment_id' => $appt])->assertStatus(409);

        // starting and finishing the consultation moves the appointment along too
        $doc = $this->doctorUser($d);
        $this->actingAs($doc)->putJson(self::API."/opd/visits/{$v['id']}/consultation", ['diagnosis' => 'Viral fever'])->assertOk()->assertJsonPath('status', 'in_consultation');
        $this->assertSame('in_consultation', DB::table('appointments')->where('id', $appt)->value('status'));
        $this->actingAs($doc)->postJson(self::API."/opd/visits/{$v['id']}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame('completed', DB::table('appointments')->where('id', $appt)->value('status'));
    }

    public function test_booked_appointments_are_listed_in_opd_by_themselves(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $pid = $this->patientRow();
        $today = Hospital::today();
        $appt = $this->appointment($d, $pid, 0);
        DB::table('appointments')->where('id', $appt)->update(['appointment_date' => $today, 'payment_status' => 'advance_paid', 'advance_amount' => 200, 'payment_reference' => 'pay_9']);
        $held = $this->appointment($d, $this->patientRow(), 0, 'pending', '11:00:00');
        DB::table('appointments')->where('id', $held)->update(['appointment_date' => $today, 'payment_status' => 'awaiting_payment']);
        $later = $this->appointment($d, $this->patientRow(), 5, 'confirmed', '12:00:00');

        $rows = $this->actingAs($this->user('receptionist'))->getJson(self::API."/opd/visits?date=$today")->assertOk()->json('items');
        $this->assertCount(1, $rows);
        $this->assertSame([$appt, 'appointment', 'waiting', 1], [$rows[0]['appointment_id'], $rows[0]['source'], $rows[0]['status'], $rows[0]['token_number']]);
        $this->assertEquals([600, 200], [$rows[0]['bill']['total'], $rows[0]['bill']['paid']]);

        // listing again never doubles it; a future booking appears on its own day
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/opd/visits?date=$today")->assertOk()->assertJsonCount(1, 'items');
        $this->assertSame(1, DB::table('opd_visits')->where('appointment_id', $appt)->count());
        $this->assertSame(0, DB::table('opd_visits')->where('appointment_id', $later)->count());
        $day = DB::table('appointments')->where('id', $later)->value('appointment_date');
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/opd/visits?date=$day")->assertJsonCount(1, 'items');

        // the booking is cancelled, so the waiting visit goes with it
        DB::table('appointments')->where('id', $appt)->update(['status' => 'cancelled']);
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/opd/visits?date=$today")->assertJsonPath('items.0.status', 'cancelled');
    }

    public function test_an_opd_patient_is_admitted_to_ipd_once(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $v = $this->walkIn($d);
        $bed = DB::table('ipd_beds')->insertGetId(['code' => 'B-1', 'display_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->doctorUser($d))->putJson(self::API."/opd/visits/{$v['id']}/consultation", ['diagnosis' => 'Appendicitis'])->assertOk();

        $this->actingAs($this->user('nurse'))->postJson(self::API."/opd/visits/{$v['id']}/admit", [])->assertStatus(403);
        $r = $this->actingAs($this->user('receptionist'))->postJson(self::API."/opd/visits/{$v['id']}/admit", ['bed_id' => $bed, 'room' => '101', 'admission_type' => 'planned', 'attendant_name' => 'Ravi', 'attendant_mobile' => '9876500000'])->assertCreated()->assertJsonPath('status', 'completed');
        $adm = DB::table('ipd_admissions')->find($r->json('admission_id'));
        $this->assertSame([$v['patient_id'], $v['id'], 'admitted', 'B-1', 'Appendicitis'], [$adm->patient_id, $adm->opd_visit_id, $adm->status, $adm->bed, $adm->case_title]);
        $this->assertSame(['101', 'planned', 'Ravi'], [$adm->room, $adm->admission_type, $adm->attendant_name]);
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/ipd/admissions/{$adm->id}")->assertOk()->assertJsonPath('attendant.name', 'Ravi');
        $this->assertSame([$d['id']], DB::table('ipd_admission_doctors')->where('admission_id', $adm->id)->pluck('doctor_id')->all());
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/opd/visits/{$v['id']}")->assertJsonPath('admission_id', $adm->id);

        $this->actingAs($this->user('receptionist'))->postJson(self::API."/opd/visits/{$v['id']}/admit", [])->assertStatus(409);
    }

    public function test_the_doctor_records_vitals_diagnosis_and_a_prescription(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $v = $this->walkIn($d);
        $nurse = $this->user('nurse');
        $this->actingAs($nurse)->putJson(self::API."/opd/visits/{$v['id']}/vitals", ['bp' => '120/80', 'pulse' => 78, 'temperature' => 38.2, 'weight' => 64])->assertOk()->assertJsonPath('consultation.vitals.bp', '120/80');
        $this->actingAs($nurse)->putJson(self::API."/opd/visits/{$v['id']}/consultation", ['diagnosis' => 'x'])->assertStatus(403);

        $doc = $this->doctorUser($d);
        $r = $this->actingAs($doc)->putJson(self::API."/opd/visits/{$v['id']}/consultation", [
            'chief_complaint' => 'Fever 3 days', 'diagnosis' => 'Viral fever', 'notes' => 'Private note', 'advice' => 'Rest', 'follow_up_date' => '2026-12-20',
            'prescriptions' => [['medicine_name' => 'Paracetamol 500', 'dosage' => '1 tab', 'frequency' => 'TDS', 'duration' => '3 days']],
        ])->assertOk()->json();
        $this->assertSame('in_consultation', $r['status']);
        $this->assertSame('Private note', $r['consultation']['notes']);
        $this->assertSame('120/80', $r['consultation']['vitals']['bp']);
        $this->assertCount(1, $r['prescriptions']);
        $this->assertNotSame('Private note', DB::table('consultations')->where('opd_visit_id', $v['id'])->value('notes_encrypted'));

        // saving again replaces the prescription
        $this->actingAs($doc)->putJson(self::API."/opd/visits/{$v['id']}/consultation", ['prescriptions' => []])->assertOk()->assertJsonCount(0, 'prescriptions');
    }

    public function test_a_doctor_sees_only_their_own_visits(): void
    {
        $dept = $this->department()['id'];
        $a = $this->doctor([$dept]);
        $b = $this->doctor([$dept]);
        $va = $this->walkIn($a);
        $vb = $this->walkIn($b);
        $docA = $this->doctorUser($a);
        $this->actingAs($docA)->getJson(self::API.'/opd/visits')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $va['id']);
        $this->actingAs($docA)->getJson(self::API."/opd/visits/{$vb['id']}")->assertStatus(404);
        $this->actingAs($docA)->putJson(self::API."/opd/visits/{$vb['id']}/consultation", ['diagnosis' => 'x'])->assertStatus(404);
    }

    public function test_lab_tests_are_ordered_from_the_visit_and_linked_back(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $v = $this->walkIn($d);
        $test = (int) DB::table('lab_tests')->where('name', 'Haemoglobin')->value('id');
        $doc = $this->doctorUser($d);

        $o = $this->actingAs($doc)->postJson(self::API.'/lab/orders', ['opd_visit_id' => $v['id'], 'test_ids' => [$test]])->assertCreated()->json();
        $this->assertSame($v['id'], $o['opd_visit_id']);
        $this->assertSame($v['patient_id'], $o['patient_id']);
        $this->actingAs($doc)->postJson(self::API.'/lab/orders', ['opd_visit_id' => $v['id'], 'test_ids' => [$test], 'payment' => ['amount' => 10, 'method' => 'cash']])->assertStatus(403);
        $this->actingAs($doc)->postJson(self::API.'/lab/orders', ['test_ids' => [$test], 'patient_id' => $v['patient_id']])->assertStatus(403);
        $other = $this->doctorUser($this->doctor([$this->department()['id']]));
        $this->actingAs($other)->postJson(self::API.'/lab/orders', ['opd_visit_id' => $v['id'], 'test_ids' => [$test]])->assertStatus(403);

        $full = $this->actingAs($doc)->getJson(self::API."/opd/visits/{$v['id']}")->assertOk()->json();
        $this->assertCount(1, $full['lab_orders']);
        $this->assertSame(1, $full['lab_count']);

        // a visit with a live lab order is cancelled from the lab first
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/opd/visits/{$v['id']}/cancel", ['reason' => 'Left'])->assertStatus(409);
    }

    public function test_cancelling_a_paid_visit_needs_refund_rights(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $v = $this->walkIn($d, ['payment' => ['amount' => 600, 'method' => 'cash']]);
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/opd/visits/{$v['id']}/cancel", ['reason' => 'Left'])->assertStatus(403);
        $this->actingAs($this->user('admin'))->postJson(self::API."/opd/visits/{$v['id']}/cancel", ['reason' => 'Left'])->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('bill.status', 'cancelled');
        $this->assertEquals(-600, DB::table('payments')->where('bill_id', $v['bill']['id'])->where('method', 'refund')->value('amount'));
        $this->actingAs($this->user('admin'))->postJson(self::API."/opd/visits/{$v['id']}/cancel", ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_the_register_totals_and_export(): void
    {
        $d = $this->doctor([$this->department()['id']]);
        $this->walkIn($d, ['payment' => ['amount' => 600, 'method' => 'cash']]);
        $this->walkIn($d);
        $rc = $this->user('receptionist');
        $s = $this->actingAs($rc)->getJson(self::API.'/opd/summary?date='.now(config('app.timezone'))->toDateString())->assertOk()->json();
        $this->assertSame(2, $s['visits']);
        $this->assertEquals([1200, 600], [$s['billed'], $s['collected']]);
        $this->actingAs($rc)->getJson(self::API.'/opd/visits?status=waiting')->assertOk()->assertJsonPath('total', 2);
        $csv = $this->actingAs($rc)->get(self::API.'/opd/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Walk In Patient', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_type' => 'opd_register']);
    }

    public function test_a_manager_runs_patients_opd_and_ipd_but_not_the_admin_areas(): void
    {
        $m = $this->user('manager');
        foreach (["/patients", "/opd/visits", "/ipd/admissions", "/lab/orders", "/billing/bills", "/dashboard/operations"] as $path) {
            $this->assertSame(200, $this->actingAs($m)->getJson(self::API.$path)->status(), $path);
        }
        $this->actingAs($m)->getJson(self::API."/dashboard/operations")->assertJsonStructure(["opd" => ["visits", "waiting", "doctors"], "ipd" => ["admitted", "beds_total"], "lab" => ["awaiting_sample"], "appointments" => ["today_total"], "unpaid_bills" => ["count"]]);
        foreach (['/users', '/roles', '/settings', '/staff', '/reports/overview', '/dashboard/summary'] as $path) {
            $this->actingAs($m)->getJson(self::API.$path)->assertForbidden();
        }
    }
}

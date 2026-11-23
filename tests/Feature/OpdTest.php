<?php

namespace Tests\Feature;

use App\Models\User;
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
}

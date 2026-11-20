<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class LabTest extends HmsTestCase
{
    private function testId(string $name): int
    {
        return (int) DB::table('lab_tests')->where('name', $name)->value('id');
    }

    private function order(array $o = []): array
    {
        $pid = $this->patientRow(['full_name' => 'Lab Patient']);

        return $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', $o + ['patient_id' => $pid, 'test_ids' => [$this->testId('Haemoglobin'), $this->testId('Lipid profile')]])
            ->assertCreated()->json();
    }

    public function test_starter_catalogue_and_access(): void
    {
        $this->assertGreaterThan(20, DB::table('lab_tests')->count());
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/lab/tests')->assertOk();
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/lab/tests')->assertStatus(403);
        $this->actingAs($this->user('patient'))->getJson(self::API.'/lab/orders')->assertStatus(403);
        $this->actingAs($this->user('lab_technician'))->getJson(self::API.'/lab/orders')->assertOk();
    }

    public function test_catalogue_is_managed_by_admins_only(): void
    {
        $body = ['name' => 'Vitamin D', 'category' => 'Biochemistry', 'sample_type' => 'Blood', 'price' => 900, 'turnaround_hours' => 24, 'unit' => 'ng/mL', 'reference_range' => '30 - 100'];
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/tests', $body)->assertStatus(403);
        $t = $this->actingAs($this->root())->postJson(self::API.'/lab/tests', $body)->assertCreated()->assertJsonPath('price', 900)->json();
        $this->assertMatchesRegularExpression('/^LT-\d{4}$/', $t['code']);
        $this->actingAs($this->root())->postJson(self::API.'/lab/tests', $body)->assertStatus(409);
        $this->actingAs($this->root())->putJson(self::API."/lab/tests/{$t['id']}", ['price' => 950, 'is_active' => false] + $body)->assertOk()->assertJsonPath('is_active', false);
        // switched-off tests cannot be ordered and are hidden from staff, but admins still see them
        $this->assertNotContains('Vitamin D', array_column($this->actingAs($this->user('receptionist'))->getJson(self::API.'/lab/tests')->json(), 'name'));
        $this->assertContains('Vitamin D', array_column($this->actingAs($this->root())->getJson(self::API.'/lab/tests?all=1')->json(), 'name'));
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_name' => 'Someone', 'test_ids' => [$t['id']]])->assertStatus(422);
    }

    public function test_an_order_creates_a_bill_and_takes_a_payment(): void
    {
        $o = $this->order(['discount' => 100, 'payment' => ['amount' => 300, 'method' => 'upi', 'reference_no' => 'UPI123']]);
        $this->assertMatchesRegularExpression('/^LAB-\d{6}-\d{5}$/', $o['order_number']);
        $this->assertSame('ordered', $o['status']);
        $this->assertEquals([700, 100, 600], [$o['bill']['subtotal'], $o['bill']['discount'], $o['bill']['total']]);
        $this->assertEquals(300, $o['bill']['paid']);
        $this->assertEquals(300, $o['bill']['balance']);
        $this->assertSame('partially_paid', $o['bill']['status']);
        $this->assertCount(2, $o['items']);
        $this->assertSame('upi', $o['payments'][0]['method']);
        $this->assertMatchesRegularExpression('/^RCT-\d{6}-\d{5}$/', $o['payments'][0]['receipt_number']);
        $this->assertSame(2, DB::table('bill_items')->where('bill_id', DB::table('bills')->value('id'))->count());

        // more than the balance is refused; the exact balance settles the bill
        $desk = $this->user('receptionist');
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 400, 'method' => 'cash'])->assertStatus(422);
        $paid = $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 300, 'method' => 'cash'])->assertCreated()->json();
        $this->assertSame('paid', $paid['bill']['status']);
        $this->assertCount(2, $paid['payments']);
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 1, 'method' => 'cash'])->assertStatus(422);
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 1, 'method' => 'bitcoin'])->assertStatus(422);
        $this->actingAs($this->user('lab_technician'))->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 1, 'method' => 'cash'])->assertStatus(403);
    }

    public function test_validation_of_orders(): void
    {
        $desk = $this->user('receptionist');
        $pid = $this->patientRow();
        $tid = $this->testId('Haemoglobin');
        $this->actingAs($desk)->postJson(self::API.'/lab/orders', ['patient_id' => $pid, 'test_ids' => []])->assertStatus(422);
        $this->actingAs($desk)->postJson(self::API.'/lab/orders', ['patient_id' => $pid, 'test_ids' => [999999]])->assertStatus(422);
        $this->actingAs($desk)->postJson(self::API.'/lab/orders', ['patient_id' => $pid, 'test_ids' => [$tid], 'discount' => 500])->assertStatus(422);
        $this->actingAs($desk)->postJson(self::API.'/lab/orders', ['patient_id' => $pid, 'test_ids' => [$tid], 'payment' => ['amount' => 5000, 'method' => 'cash']])->assertStatus(422);
        $this->actingAs($desk)->postJson(self::API.'/lab/orders', ['test_ids' => [$tid]])->assertStatus(422); // no patient at all
        $this->assertSame(0, DB::table('lab_orders')->count());
        $this->assertSame(0, DB::table('bills')->count()); // nothing half-created
    }

    public function test_a_walk_in_is_registered_as_a_patient(): void
    {
        $o = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_name' => 'Walk In', 'patient_mobile' => '+91 98765 43210', 'test_ids' => [$this->testId('ESR')]])->assertCreated()->json();
        $this->assertDatabaseHas('patients', ['id' => $o['patient_id'], 'full_name' => 'Walk In', 'mobile' => '9876543210', 'date_of_birth' => null]);
        $this->actingAs($this->root())->getJson(self::API.'/patients?incomplete=1')->assertJsonFragment(['full_name' => 'Walk In']);
    }

    public function test_sample_to_released_report(): void
    {
        $o = $this->order();
        $desk = $this->user('receptionist');
        $lab = $this->user('lab_technician');
        $item = $o['items'][0]['id'];

        $this->actingAs($lab)->putJson(self::API."/lab/orders/{$o['id']}/results", ['results' => [['item_id' => $item, 'result_value' => '11.2']]])->assertStatus(409); // no sample yet
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'completed'])->assertStatus(409);
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'sample_collected'])->assertOk()->assertJsonPath('status', 'sample_collected');

        // results move it to processing; a report cannot be released with a test still blank, or by the front desk
        $res = $this->actingAs($lab)->putJson(self::API."/lab/orders/{$o['id']}/results", ['results' => [['item_id' => $item, 'result_value' => '11.2', 'flag' => 'low', 'result_unit' => 'g/dL', 'reference_range' => '12 - 16']]])->assertOk()->json();
        $this->assertSame('processing', $res['status']);
        $this->assertSame('low', $res['items'][0]['flag']);
        $this->actingAs($lab)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'completed'])->assertStatus(409);
        $this->actingAs($lab)->putJson(self::API."/lab/orders/{$o['id']}/results", ['results' => [['item_id' => $o['items'][1]['id'], 'result_value' => 'Total cholesterol 180']]])->assertOk();
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'completed'])->assertStatus(403);
        $this->actingAs($lab)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('status', 'completed');
        $this->actingAs($lab)->putJson(self::API."/lab/orders/{$o['id']}/results", ['results' => [['item_id' => 424242, 'result_value' => 'x']]])->assertStatus(400);

        // a finished order cannot be cancelled
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/cancel", ['reason' => 'mistake'])->assertStatus(409);
    }

    public function test_cancelling_refunds_what_was_paid(): void
    {
        $o = $this->order(['payment' => ['amount' => 400, 'method' => 'cash']]);
        $desk = $this->user('receptionist');
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/cancel", [])->assertStatus(422);
        $c = $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/cancel", ['reason' => 'Patient left'])->assertOk()->json();
        $this->assertSame('cancelled', $c['status']);
        $this->assertSame('cancelled', $c['bill']['status']);
        $this->assertEquals(0, $c['bill']['paid']);
        $this->assertEquals([400, -400], array_column($c['payments'], 'amount'));
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/payments", ['amount' => 1, 'method' => 'cash'])->assertStatus(409);
        $this->actingAs($desk)->postJson(self::API."/lab/orders/{$o['id']}/cancel", ['reason' => 'again'])->assertStatus(409);
    }

    public function test_listing_filters_and_doctor_scope(): void
    {
        $dept = $this->department();
        $drA = $this->doctor([$dept['id']]);
        $drB = $this->doctor([$dept['id']]);
        $a = $this->order(['doctor_id' => $drA['id']]);
        $b = $this->order(['doctor_id' => $drB['id'], 'payment' => ['amount' => 700, 'method' => 'card']]);
        $root = $this->actingAs($this->root());

        $this->assertCount(2, $root->getJson(self::API.'/lab/orders')->assertOk()->json('items'));
        $this->assertSame([$a['id']], array_column($root->getJson(self::API.'/lab/orders?unpaid=1')->json('items'), 'id'));
        $this->assertSame([$b['id']], array_column($root->getJson(self::API.'/lab/orders?q=Lipid&patient_id='.$b['patient_id'])->json('items'), 'id'));
        $this->assertSame([$a['id']], array_column($root->getJson(self::API.'/lab/orders?q='.urlencode($a['order_number']))->json('items'), 'id'));
        $this->assertSame([], $root->getJson(self::API.'/lab/orders?status=completed')->json('items'));
        $this->assertStringContainsString('Haemoglobin', $root->getJson(self::API.'/lab/orders')->json('items.0.tests'));

        // a doctor sees only the orders they placed
        $docUser = \App\Models\User::find(DB::table('doctors')->where('id', $drA['id'])->value('user_id'));
        $this->actingAs($docUser)->getJson(self::API.'/lab/orders')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $a['id']);
        $this->actingAs($docUser)->getJson(self::API."/lab/orders/{$b['id']}")->assertStatus(404);
        $this->actingAs($docUser)->getJson(self::API."/lab/orders/{$a['id']}")->assertOk();
        $this->actingAs($docUser)->postJson(self::API.'/lab/orders', ['patient_name' => 'Nope', 'test_ids' => [$this->testId('ESR')]])->assertStatus(403);
    }

    public function test_a_patient_sees_their_report_only_once_released(): void
    {
        $u = $this->user('patient');
        $pid = $this->patientRow(['user_id' => $u->id, 'mobile' => '9123456780', 'email' => 'p@example.com']);
        $o = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_id' => $pid, 'test_ids' => [$this->testId('Haemoglobin')]])->assertCreated()->json();
        $lab = $this->user('lab_technician');
        $this->actingAs($lab)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'sample_collected'])->assertOk();
        $this->actingAs($lab)->putJson(self::API."/lab/orders/{$o['id']}/results", ['results' => [['item_id' => $o['items'][0]['id'], 'result_value' => '13.5']]])->assertOk();

        $this->actingAs($u)->getJson(self::API.'/portal/lab-orders')->assertOk()->assertJsonCount(1)->assertJsonPath('0.items', []); // not released yet: no results shown
        $this->actingAs($lab)->postJson(self::API."/lab/orders/{$o['id']}/status", ['status' => 'completed'])->assertOk();
        $this->actingAs($u)->getJson(self::API.'/portal/lab-orders')->assertOk()->assertJsonPath('0.items.0.result_value', '13.5')->assertJsonPath('0.status', 'completed');

        // another patient sees nothing of it
        $other = $this->user('patient');
        $this->patientRow(['user_id' => $other->id, 'mobile' => '9123456781', 'email' => 'q@example.com']);
        $this->actingAs($other)->getJson(self::API.'/portal/lab-orders')->assertOk()->assertJsonCount(0);
    }

    private function admitted(): array
    {
        $a = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-01', 'patient_name' => 'Ward Patient', 'bed' => 'G-1', 'case_title' => 'LSCS'])
            ->assertCreated()->json();

        return $a;
    }

    public function test_tests_for_an_admitted_patient_post_to_the_ipd_register(): void
    {
        $a = $this->admitted();
        $o = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_id' => $a['patient_id'], 'discount' => 100,
            'test_ids' => [$this->testId('Haemoglobin'), $this->testId('Lipid profile')]])->assertCreated()->assertJsonPath('admission_id', $a['id'])->assertJsonPath('ipd_bed', 'G-1')->json();

        $entry = DB::table('ipd_entries')->where('lab_order_id', $o['id'])->first();
        $this->assertSame('lab', DB::table('ipd_heads')->where('id', $entry->head_id)->value('code'));
        $this->assertSame($a['id'], (int) $entry->admission_id);
        $this->assertEquals(600, $entry->amount); // after the discount
        $this->assertSame(1, DB::table('ipd_entries')->where('admission_id', $a['id'])->count());
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/ipd/admissions/{$a['id']}")->assertOk()->assertJsonPath('entries.0.lab_order_id', $o['id'])->assertJsonPath('charges', 600);

        // staff cannot edit or delete a charge that belongs to a lab order
        $this->actingAs($this->user('accountant'))->deleteJson(self::API."/ipd/entries/{$entry->id}")->assertStatus(409);
        $this->actingAs($this->user('accountant'))->putJson(self::API."/ipd/entries/{$entry->id}", ['head_id' => $entry->head_id, 'entry_date' => '2026-11-02', 'amount' => 1])->assertStatus(409);

        // cancelling the order takes it back out
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/lab/orders/{$o['id']}/cancel", ['reason' => 'Ordered by mistake'])->assertOk();
        $this->assertSame(0, DB::table('ipd_entries')->where('admission_id', $a['id'])->count());
    }

    public function test_a_lab_only_visitor_is_not_in_the_ipd_register(): void
    {
        $walkIn = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_name' => 'Walk In', 'patient_mobile' => '9876543210', 'test_ids' => [$this->testId('Haemoglobin')]])
            ->assertCreated()->assertJsonPath('admission_id', null)->json();
        $this->assertSame(0, DB::table('ipd_entries')->count());

        // an admitted patient can still be tested as an outpatient on request
        $a = $this->admitted();
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_id' => $a['patient_id'], 'bill_to_admission' => false, 'test_ids' => [$this->testId('Haemoglobin')]])
            ->assertCreated()->assertJsonPath('admission_id', null);
        $this->assertSame(0, DB::table('ipd_entries')->count());

        // a discharged patient is a lab-only visitor again
        $this->actingAs($this->user('receptionist'))->putJson(self::API."/ipd/admissions/{$a['id']}", ['status' => 'discharged', 'discharge_date' => '2026-11-05'] + $this->actingAs($this->user('receptionist'))->getJson(self::API."/ipd/admissions/{$a['id']}")->json())->assertOk();
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_id' => $a['patient_id'], 'test_ids' => [$this->testId('Haemoglobin')]])->assertCreated()->assertJsonPath('admission_id', null);

        // the lab list can be split into in-patients and lab-only visits
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/lab/orders?visit=walk_in')->assertOk()->assertJsonPath('total', 3);
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/lab/orders?visit=ipd')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($walkIn['id'], $this->actingAs($this->user('receptionist'))->getJson(self::API.'/lab/orders?q=Walk')->json('items.0.id'));
    }

    public function test_the_order_screen_can_ask_whether_a_patient_is_admitted(): void
    {
        $a = $this->admitted();
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/lab/admission-for/{$a['patient_id']}")->assertOk()->assertJsonPath('admission.bed', 'G-1');
        $other = $this->patientRow(['full_name' => 'Not Admitted']);
        $this->actingAs($this->user('receptionist'))->getJson(self::API."/lab/admission-for/$other")->assertOk()->assertJsonPath('admission', null);
        $this->actingAs($this->user('nurse'))->getJson(self::API."/lab/admission-for/$other")->assertStatus(403);
    }
}

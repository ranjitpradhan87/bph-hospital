<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class BillingReportsTest extends HmsTestCase
{
    private function bill(array $o = [], string $as = 'receptionist'): array
    {
        $pid = $o['patient_id'] ?? $this->patientRow(['full_name' => 'Bill Patient']);

        return $this->actingAs($this->user($as))->postJson(self::API.'/billing/bills', $o + ['patient_id' => $pid,
            'items' => [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 600], ['description' => 'Dressing', 'quantity' => 2, 'unit_price' => 150]]])->assertCreated()->json();
    }

    public function test_billing_access(): void
    {
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills')->assertOk();
        $this->actingAs($this->user('accountant'))->getJson(self::API.'/billing/bills')->assertOk();
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/billing/bills')->assertStatus(403);
        $this->actingAs($this->user('patient'))->getJson(self::API.'/billing/bills')->assertStatus(403);
    }

    public function test_a_bill_is_built_from_items_and_collects_payments(): void
    {
        $b = $this->bill(['discount' => 100, 'payment' => ['amount' => 300, 'method' => 'cash']]);
        $this->assertMatchesRegularExpression('/^BILL-\d{6}-\d{5}$/', $b['bill_number']);
        $this->assertEquals([900, 100, 800, 300, 500], [$b['subtotal'], $b['discount'], $b['total'], $b['paid'], $b['balance']]);
        $this->assertSame('partially_paid', $b['status']);
        $this->assertSame('counter', $b['source']);
        $this->assertCount(2, $b['items']);
        $this->assertEquals(300, $b['items'][1]['line_total']);

        $this->actingAs($this->user('receptionist'))->postJson(self::API."/billing/bills/{$b['id']}/payments", ['amount' => 600, 'method' => 'upi'])->assertStatus(422);
        $paid = $this->actingAs($this->user('receptionist'))->postJson(self::API."/billing/bills/{$b['id']}/payments", ['amount' => 500, 'method' => 'upi', 'reference_no' => 'U1'])->assertCreated()->json();
        $this->assertSame('paid', $paid['status']);
        $this->assertCount(2, $paid['payments']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment', 'entity_type' => 'bill']);
    }

    public function test_bad_bills_are_refused(): void
    {
        $pid = $this->patientRow();
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/billing/bills', ['patient_id' => $pid, 'items' => []])->assertStatus(422);
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/billing/bills', ['patient_id' => $pid, 'discount' => 700, 'items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 600]]])->assertStatus(422);
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/billing/bills', ['patient_id' => 99999, 'items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 600]]])->assertStatus(400);
        // the appointment must be this patient's
        $d = $this->doctor([$this->department()['id']]);
        $other = $this->patientRow(['full_name' => 'Someone Else']);
        $appt = $this->appointment($d, $other, 0);
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/billing/bills', ['patient_id' => $pid, 'appointment_id' => $appt, 'items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 600]]])->assertStatus(400);
    }

    public function test_only_refund_holders_cancel_a_paid_bill(): void
    {
        $unpaid = $this->bill();
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/billing/bills/{$unpaid['id']}/cancel", ['reason' => 'Wrong patient'])->assertOk()->assertJsonPath('status', 'cancelled');

        $paid = $this->bill(['payment' => ['amount' => 200, 'method' => 'cash']]);
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/billing/bills/{$paid['id']}/cancel", ['reason' => 'Wrong patient'])->assertStatus(403);
        $this->actingAs($this->user('accountant'))->postJson(self::API."/billing/bills/{$paid['id']}/cancel", ['reason' => 'Wrong patient'])->assertOk()->assertJsonPath('paid', 0);
        $this->assertEquals(-200, DB::table('payments')->where('bill_id', $paid['id'])->where('method', 'refund')->value('amount'));
        $this->actingAs($this->user('accountant'))->postJson(self::API."/billing/bills/{$paid['id']}/payments", ['amount' => 10, 'method' => 'cash'])->assertStatus(409);
    }

    public function test_lab_bills_show_up_but_are_cancelled_from_the_lab(): void
    {
        $lab = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_name' => 'Lab Only', 'test_ids' => [(int) DB::table('lab_tests')->where('name', 'Haemoglobin')->value('id')]])->assertCreated()->json();
        $row = $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills?source=lab')->assertOk()->assertJsonPath('total', 1)->json('items.0');
        $this->assertSame('lab', $row['source']);
        $this->assertSame($lab['order_number'], $row['lab_order_number']);
        $this->actingAs($this->user('accountant'))->postJson(self::API."/billing/bills/{$row['id']}/cancel", ['reason' => 'No longer needed'])->assertStatus(409);
        $this->bill();
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills?source=counter')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_bill_list_filters_and_totals(): void
    {
        $a = $this->bill(['payment' => ['amount' => 900, 'method' => 'cash']]);
        $b = $this->bill(['payment' => ['amount' => 100, 'method' => 'cash']]);
        $c = $this->bill();
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/billing/bills/{$c['id']}/cancel", ['reason' => 'Duplicate bill'])->assertOk();

        $r = $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills')->assertOk()->assertJsonPath('total', 3)->json();
        $this->assertEquals(['bills' => 2, 'billed' => 1800, 'collected' => 1000, 'outstanding' => 800], $r['summary']); // cancelled bills do not count
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills?unpaid=1')->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $b['id']);
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills?status=paid')->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $a['id']);
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/billing/bills?q=Bill%20Pat')->assertJsonPath('total', 3);
    }

    public function test_reports_split_money_by_where_it_came_from(): void
    {
        $today = date('Y-m-d');
        $this->bill(['payment' => ['amount' => 500, 'method' => 'cash']]);
        $this->bill(['payment' => ['amount' => 250, 'method' => 'upi']]);

        $d = $this->doctor([$this->department()['id']]);
        $pid = $this->patientRow(['full_name' => 'Online Payer']);
        $appt = $this->appointment($d, $pid, 0);
        DB::table('appointments')->where('id', $appt)->update(['payment_mode' => 'advance_online', 'payment_status' => 'paid', 'advance_amount' => 300, 'paid_at' => now()]);

        $a = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', ['admission_date' => $today, 'patient_name' => 'Ward Patient', 'bed' => 'G-1', 'case_title' => 'LSCS'])->assertCreated()->json();
        $this->actingAs($this->user('accountant'))->postJson(self::API."/ipd/admissions/{$a['id']}/entries", ['head_id' => (int) DB::table('ipd_heads')->where('code', 'cash')->value('id'), 'entry_date' => $today, 'amount' => 1000])->assertCreated();
        $this->actingAs($this->user('accountant'))->postJson(self::API."/ipd/admissions/{$a['id']}/entries", ['head_id' => (int) DB::table('ipd_heads')->where('code', 'doctor_fee')->value('id'), 'entry_date' => $today, 'amount' => 400])->assertCreated();

        $r = $this->actingAs($this->user('accountant'))->getJson(self::API."/reports/overview?from=$today&to=$today")->assertOk()->json();
        $this->assertTrue($r['financial']);
        $this->assertEquals([750, 300, 1000, 2050], [$r['collections']['by_source']['bills'], $r['collections']['by_source']['online_advance'], $r['collections']['by_source']['ipd'], $r['collections']['total']]);
        $this->assertCount(1, $r['collections']['daily']);
        $this->assertEquals(2050, $r['collections']['daily'][0]['total']);
        $this->assertEqualsCanonicalizing(['cash' => 500, 'upi' => 250], collect($r['collections']['by_method'])->pluck('amount', 'method')->all());
        $this->assertEquals([1000, 400], [$r['ipd']['received'], $r['ipd']['payouts']]);
        $this->assertSame(1, $r['ipd']['admitted']);
        $this->assertSame(1, $r['appointments']['total']);
        $this->assertSame(1, $r['appointments']['by_status']['confirmed']);
        $this->assertSame(1, $r['appointments']['by_department'][0]['total']);
    }

    public function test_reports_include_lab_and_outstanding_dues(): void
    {
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/lab/orders', ['patient_name' => 'Walk In', 'test_ids' => [(int) DB::table('lab_tests')->where('name', 'Haemoglobin')->value('id')]])->assertCreated();
        $this->bill();
        $r = $this->actingAs($this->user('accountant'))->getJson(self::API.'/reports/overview')->assertOk()->json();
        $this->assertSame(1, $r['lab']['orders']);
        $this->assertSame(1, $r['lab']['lab_only']);
        $this->assertSame('Haemoglobin', $r['lab']['top_tests'][0]['name']);
        $this->assertSame(2, $r['outstanding']['bills']);
        $this->assertGreaterThan(900, $r['outstanding']['amount']);
        $this->assertEquals(900, $r['outstanding']['top'][0]['balance']);
    }

    public function test_money_is_hidden_from_operational_viewers_and_reports_are_guarded(): void
    {
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/reports/overview')->assertStatus(403);
        $this->actingAs($this->user('patient'))->getJson(self::API.'/reports/overview')->assertStatus(403);
        $this->actingAs($this->user('accountant'))->getJson(self::API.'/reports/overview?from=2026-01-01&to=2027-06-01')->assertStatus(422);
        $this->actingAs($this->user('accountant'))->getJson(self::API.'/reports/overview?from=2026-02-01&to=2026-01-01')->assertStatus(422);

        $role = DB::table('roles')->where('code', 'receptionist')->value('id');
        $perm = (int) DB::table('permissions')->where('code', 'reports.view')->value('id');
        $u = $this->user('receptionist');
        $u->roles()->detach();
        $viewer = \App\Models\Role::create(['code' => 'ops_viewer', 'name' => 'Ops viewer', 'is_system' => false]);
        $viewer->permissions()->attach($perm);
        $u->roles()->attach($viewer->id);
        $r = $this->actingAs($u->fresh())->getJson(self::API.'/reports/overview')->assertOk()->json();
        $this->assertFalse($r['financial']);
        $this->assertArrayNotHasKey('collections', $r);
        $this->assertArrayNotHasKey('outstanding', $r);
        $this->actingAs($u->fresh())->get(self::API.'/reports/collections/export')->assertStatus(403);
        $this->assertNotNull($role);
    }

    public function test_collections_export_is_a_csv(): void
    {
        $this->bill(['payment' => ['amount' => 500, 'method' => 'cash']]);
        $today = date('Y-m-d');
        $res = $this->actingAs($this->user('accountant'))->get(self::API."/reports/collections/export?from=$today&to=$today")->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDate,\"Bills (counter and lab)\",\"Online advance\",\"IPD receipts\",Total", $csv);
        $this->assertStringContainsString(date('d-m-Y').',500,0,0,500', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_type' => 'collections_report']);
    }
}

<?php

namespace Tests\Feature;

use App\Support\Hospital;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class PublicDashboardTest extends HmsTestCase
{
    private function slot(array $doc, int $daysAhead, string $start, array $o = []): int
    {
        return DB::table('appointment_slots')->insertGetId($o + [
            'doctor_id' => $doc['id'], 'department_id' => $doc['departments'][0]['id'], 'slot_date' => Hospital::now()->addDays($daysAhead)->toDateString(),
            'start_time' => $start, 'end_time' => date('H:i:s', strtotime($start) + 900), 'capacity' => 1, 'booked_count' => 0, 'consultation_fee' => 600,
            'status' => 'available', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_public_settings_only_expose_public_keys(): void
    {
        $res = $this->getJson(self::API.'/public/settings')->assertOk();
        $this->assertArrayHasKey('name', $res->json('settings.hospital'));
        $this->assertArrayNotHasKey('timezone', $res->json('settings.hospital'));
        $this->assertArrayNotHasKey('notifications', $res->json('settings'));
        $this->assertArrayNotHasKey('invoice_prefix', $res->json('settings.billing') ?? []);
        $this->assertArrayHasKey('departments', $res->json('stats'));
    }

    public function test_departments_hide_inactive_and_count_only_bookable_doctors(): void
    {
        $a = $this->department('Cardiology');
        $b = $this->department('Hidden Dept');
        $doc = $this->doctor([$a['id']]);
        $off = $this->doctor([$a['id']]);
        $this->actingAs($this->root())->patchJson(self::API."/doctors/{$off['id']}/status", ['status' => 'on_leave'])->assertOk();
        $this->actingAs($this->root())->patchJson(self::API."/departments/{$b['id']}/status", ['is_active' => false])->assertOk();

        $list = collect($this->getJson(self::API.'/public/departments')->assertOk()->json());
        $this->assertSame(1, $list->firstWhere('slug', 'cardiology')['doctor_count']);
        $this->assertNull($list->firstWhere('slug', $b['slug']));
        $this->getJson(self::API.'/public/departments/'.$b['slug'])->assertStatus(404);
        $this->getJson(self::API.'/public/departments/cardiology')->assertOk()->assertJsonPath('name', 'Cardiology');
    }

    public function test_doctor_listing_filters_and_never_leaks_contact_details(): void
    {
        $dept = $this->department('Neurology');
        $doc = $this->doctor([$dept['id']], ['full_name' => 'Dr Mira Nair', 'specialization' => 'Neurologist', 'is_featured' => true]);
        $other = $this->doctor([$this->department()['id']], ['full_name' => 'Dr Other']);
        $this->slot($doc, 2, '10:00:00');

        $res = $this->getJson(self::API.'/public/doctors?department=neurology')->assertOk();
        $this->assertSame(1, $res->json('total'));
        $item = $res->json('items.0');
        $this->assertSame('Dr Mira Nair', $item['full_name']);
        $this->assertSame(1, $item['available_slot_count']);
        $this->assertSame(Hospital::now()->addDays(2)->toDateString(), $item['next_available_date']);
        foreach (['email', 'mobile', 'registration_number', 'user_id', 'password'] as $leak) {
            $this->assertArrayNotHasKey($leak, $item);
        }
        $this->assertSame(1, $this->getJson(self::API.'/public/doctors?search=Mira')->json('total'));
        $this->assertSame(1, $this->getJson(self::API.'/public/doctors?specialization=Neurologist')->json('total'));
        $this->assertSame(1, $this->getJson(self::API.'/public/doctors?featured=1')->json('total'));
        $on = Hospital::now()->addDays(2)->toDateString();
        $this->assertSame([$doc['id']], array_column($this->getJson(self::API."/public/doctors?available_on=$on")->json('items'), 'id'));
        $this->assertContains('Neurologist', $this->getJson(self::API.'/public/specializations')->json());
        $this->getJson(self::API."/public/doctors/{$doc['id']}")->assertOk()->assertJsonPath('registration_number', $doc['registration_number']);
    }

    public function test_only_bookable_slots_are_offered(): void
    {
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $ok = $this->slot($doc, 2, '09:00:00');
        $this->slot($doc, 2, '09:15:00', ['is_published' => false]);
        $this->slot($doc, 2, '09:30:00', ['status' => 'blocked']);
        $this->slot($doc, 2, '09:45:00', ['booked_count' => 1]);
        $this->slot($doc, -1, '09:00:00');
        $this->slot($doc, 400, '09:00:00');
        $date = Hospital::now()->addDays(2)->toDateString();

        $slots = $this->getJson(self::API."/public/doctors/{$doc['id']}/slots?date=$date")->assertOk()->json();
        $this->assertSame([$ok], array_column($slots, 'id'));
        $this->assertSame(1, $slots[0]['remaining']);

        DB::table('holidays')->insert(['holiday_date' => $date, 'name' => 'Festival', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->getJson(self::API."/public/doctors/{$doc['id']}/slots?date=$date")->json());
        DB::table('holidays')->delete();
        DB::table('doctor_leaves')->insert(['doctor_id' => $doc['id'], 'start_date' => $date, 'end_date' => $date, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->getJson(self::API."/public/doctors/{$doc['id']}/slots?date=$date")->json());
        $this->getJson(self::API.'/public/doctors/9999/slots?date='.$date)->assertStatus(404);
    }

    public function test_weekly_hours_shown_on_profile(): void
    {
        $doc = $this->doctor([$this->department()['id']]);
        DB::table('doctor_schedules')->insert(['doctor_id' => $doc['id'], 'department_id' => $doc['departments'][0]['id'], 'day_of_week' => 1,
            'start_time' => '09:00:00', 'end_time' => '13:00:00', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson(self::API."/public/doctors/{$doc['id']}")->assertJsonPath('weekly_hours.0.day_of_week', 1)->assertJsonPath('weekly_hours.0.start_time', '09:00:00');
    }

    public function test_contact_form_honeypot_validation_and_rate_limit(): void
    {
        $body = ['name' => 'Ravi Kumar', 'email' => 'Ravi@Example.com', 'message' => 'Do you have a cardiologist on Saturdays?', 'phone' => '+91 98765 43210'];
        $this->postJson(self::API.'/public/contact', $body)->assertCreated()->assertJson(['status' => 'received']);
        $row = DB::table('contact_messages')->first();
        $this->assertSame('ravi@example.com', $row->email);
        $this->assertSame('9876543210', $row->phone);

        $this->postJson(self::API.'/public/contact', $body + ['website' => 'http://spam.example'])->assertCreated();
        $this->assertDatabaseCount('contact_messages', 1); // honeypot: pretend success, store nothing
        $this->postJson(self::API.'/public/contact', ['name' => 'X', 'email' => 'bad', 'message' => 'short'])->assertStatus(422);
        $this->postJson(self::API.'/public/contact', $body)->assertCreated();
        $this->postJson(self::API.'/public/contact', $body)->assertCreated();
        $this->postJson(self::API.'/public/contact', $body)->assertStatus(429);
    }

    public function test_dashboard_summary_counts_and_revenue_visibility(): void
    {
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $pid = $this->patientRow();
        $this->appointment($doc, $pid, 0, 'confirmed', '23:00:00');
        $this->appointment($doc, $pid, 0, 'completed', '00:10:00');
        DB::table('contact_messages')->insert(['name' => 'A', 'email' => 'a@example.com', 'message' => 'Hello there friend', 'created_at' => now(), 'updated_at' => now()]);
        $billId = DB::table('bills')->insertGetId(['bill_number' => 'INV-1', 'patient_id' => $pid, 'bill_date' => Hospital::today(), 'total_amount' => 1000, 'paid_amount' => 400, 'status' => 'partially_paid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['receipt_number' => 'RC-1', 'bill_id' => $billId, 'amount' => 400, 'method' => 'cash', 'status' => 'success', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $s = $this->actingAs($this->root())->getJson(self::API.'/dashboard/summary')->assertOk()->json();
        $this->assertSame(1, $s['patients_total']);
        $this->assertSame(1, $s['doctors_active']);
        $this->assertSame(2, $s['appointments']['today_total']);
        $this->assertSame(1, $s['appointments']['today_by_status']['completed']);
        $this->assertSame(1, $s['enquiries_new']);
        $this->assertEquals(400.0, $s['revenue']['today']);
        $this->assertEquals(600.0, $s['revenue']['pending_amount']);

        // Someone who may see the dashboard but not finance gets no money figures.
        $this->actingAs($this->root())->postJson(self::API.'/roles', ['code' => 'ops_viewer', 'name' => 'Ops viewer', 'permissions' => ['dashboard.view']])->assertCreated();
        $ops = $this->user('ops_viewer');
        $this->assertNull($this->actingAs($ops)->getJson(self::API.'/dashboard/summary')->assertOk()->json('revenue'));
        $this->actingAs($ops)->getJson(self::API.'/dashboard/revenue-trend')->assertStatus(403);
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/dashboard/summary')->assertStatus(403);
        $this->actingAs($this->root())->getJson(self::API.'/dashboard/revenue-trend?months=3')->assertOk()->assertJsonCount(3);
        $this->actingAs($this->root())->getJson(self::API.'/dashboard/appointment-trend?days=7')->assertOk()->assertJsonCount(7);
        $stats = $this->actingAs($this->root())->getJson(self::API.'/dashboard/department-stats')->assertOk()->json();
        $this->assertSame($dept['id'], $stats[0]['department_id']);
        $this->assertSame(2, $stats[0]['total']);
    }
}

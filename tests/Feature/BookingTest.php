<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Hospital;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class BookingTest extends HmsTestCase
{
    private array $dept;

    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = $this->department();
        $this->doc = $this->doctor([$this->dept['id']]);
    }

    private function slotAt(Carbon $start, int $capacity = 1, array $o = []): int
    {
        return DB::table('appointment_slots')->insertGetId($o + [
            'doctor_id' => $this->doc['id'], 'department_id' => $this->dept['id'], 'slot_date' => $start->toDateString(), 'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(15)->format('H:i:s'), 'capacity' => $capacity, 'booked_count' => 0, 'consultation_fee' => 600,
            'status' => 'available', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function future(int $days = 3, string $time = '10:00'): Carbon
    {
        return Carbon::parse(Hospital::now()->addDays($days)->toDateString().' '.$time, Hospital::tz());
    }

    /** A signed-in patient: [User, patientId]. */
    private function patientUser(): array
    {
        $u = $this->user('patient');
        $pid = $this->patientRow(['user_id' => $u->id, 'email' => "pat{$u->id}@example.com"]);
        DB::table('patients')->where('id', $pid)->update(['patient_code' => sprintf('PAT-%06d', $pid)]);

        return [$u, $pid];
    }

    private function book(User $u, int $slot, array $o = [])
    {
        return $this->actingAs($u)->postJson(self::API.'/appointments', ['slot_id' => $slot] + $o);
    }

    // ---------- schedules and generation ----------

    public function test_schedule_validation_and_overlap(): void
    {
        $admin = $this->root();
        $base = ['department_id' => $this->dept['id'], 'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_min' => 30, 'breaks' => [['start_time' => '10:00', 'end_time' => '10:30', 'reason' => 'Tea']]];
        $this->actingAs($this->user('nurse'))->postJson(self::API."/doctors/{$this->doc['id']}/schedules", $base)->assertStatus(403);
        $made = $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", $base)->assertCreated()->json();
        $this->assertSame('10:00', $made['breaks'][0]['start_time']);
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", ['start_time' => '11:00', 'end_time' => '13:00', 'breaks' => []] + $base)->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", ['day_of_week' => 1] + $base)->assertCreated();
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", ['end_time' => '08:00', 'day_of_week' => 2] + $base)->assertStatus(422);
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", ['department_id' => $this->department()['id'], 'day_of_week' => 3] + $base)->assertStatus(400);
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", ['breaks' => [['start_time' => '08:00', 'end_time' => '08:30']], 'day_of_week' => 4] + $base)->assertStatus(422);
        $this->actingAs($admin)->getJson(self::API."/doctors/{$this->doc['id']}/schedules")->assertJsonCount(2);
        $this->actingAs($admin)->putJson(self::API."/schedules/{$made['id']}", ['capacity_per_slot' => 2] + $base)->assertOk()->assertJsonPath('capacity_per_slot', 2);
    }

    public function test_slot_generation_respects_breaks_holidays_leave_and_is_idempotent(): void
    {
        $admin = $this->root();
        $monday = Hospital::now()->next('Monday');
        $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/schedules", [
            'department_id' => $this->dept['id'], 'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_min' => 30,
            'breaks' => [['start_time' => '10:00', 'end_time' => '10:30']], 'effective_from' => Hospital::today(),
        ])->assertCreated();
        $from = $monday->toDateString();
        $to = $monday->copy()->addDays(21)->toDateString(); // four Mondays

        $res = $this->actingAs($admin)->postJson(self::API.'/slots/generate', ['doctor_id' => $this->doc['id'], 'from' => $from, 'to' => $to])->assertOk()->json();
        $this->assertSame(20, $res['created']); // 5 slots x 4 days
        $times = DB::table('appointment_slots')->where('slot_date', $from)->orderBy('start_time')->pluck('start_time')->all();
        $this->assertSame(['09:00:00', '09:30:00', '10:30:00', '11:00:00', '11:30:00'], $times);
        $this->assertSame(0, DB::table('appointment_slots')->where('is_published', false)->count());

        $again = $this->actingAs($admin)->postJson(self::API.'/slots/generate', ['doctor_id' => $this->doc['id'], 'from' => $from, 'to' => $to])->json();
        $this->assertSame(['created' => 0, 'skipped' => 20], ['created' => $again['created'], 'skipped' => $again['skipped']]);

        // A holiday and a leave day are skipped when generating further ahead.
        $later = $monday->copy()->addDays(28);
        $later2 = $monday->copy()->addDays(35);
        DB::table('holidays')->insert(['holiday_date' => $later->toDateString(), 'name' => 'Fest', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('doctor_leaves')->insert(['doctor_id' => $this->doc['id'], 'start_date' => $later2->toDateString(), 'end_date' => $later2->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->actingAs($admin)->postJson(self::API.'/slots/generate', ['doctor_id' => $this->doc['id'], 'from' => $later->toDateString(), 'to' => $later2->toDateString()])->json();
        $this->assertSame(0, $res['created']);
        $this->assertEqualsCanonicalizing([$later->toDateString(), $later2->toDateString()], $res['days_closed']);

        // Past dates are never generated; huge ranges are refused.
        $this->actingAs($admin)->postJson(self::API.'/slots/generate', ['doctor_id' => $this->doc['id'], 'from' => '2020-01-01', 'to' => '2020-02-01'])->assertOk()->assertJsonPath('created', 0);
        $this->actingAs($admin)->postJson(self::API.'/slots/generate', ['doctor_id' => $this->doc['id'], 'from' => $from, 'to' => '2031-01-01'])->assertStatus(400);

        $list = $this->actingAs($admin)->getJson(self::API."/slots?from=$from&to=$from&doctor_id={$this->doc['id']}")->assertOk();
        $this->assertSame(5, $list->json('total'));
        $this->assertSame('available', $list->json('items.0.display_status'));
    }

    public function test_leave_closes_open_slots_reports_bookings_and_reopens_on_removal(): void
    {
        $admin = $this->root();
        $when = $this->future(5);
        $open = $this->slotAt($when);
        [$u] = $this->patientUser();
        $booked = $this->slotAt($when->copy()->addMinutes(30));
        $this->book($u, $booked)->assertCreated();

        $leave = $this->actingAs($admin)->postJson(self::API."/doctors/{$this->doc['id']}/leaves", ['start_date' => $when->toDateString(), 'end_date' => $when->toDateString(), 'reason' => 'Conference'])->assertCreated();
        $this->assertSame(1, $leave->json('slots_closed'));
        $this->assertSame(1, $leave->json('bookings_affected'));
        $this->assertSame('blocked', DB::table('appointment_slots')->where('id', $open)->value('status'));
        $this->assertSame('available', DB::table('appointment_slots')->where('id', $booked)->value('status'));
        $this->book($this->patientUser()[0], $open)->assertStatus(409);

        $this->actingAs($admin)->deleteJson(self::API.'/leaves/'.$leave->json('id'))->assertStatus(204);
        $this->assertSame('available', DB::table('appointment_slots')->where('id', $open)->value('status'));
    }

    public function test_slot_actions_create_block_publish_delete(): void
    {
        $admin = $this->root();
        $when = $this->future(4);
        $body = ['doctor_id' => $this->doc['id'], 'department_id' => $this->dept['id'], 'slot_date' => $when->toDateString(), 'start_time' => '14:00', 'end_time' => '14:20', 'capacity' => 2];
        $slot = $this->actingAs($admin)->postJson(self::API.'/slots', $body)->assertCreated()->json();
        $this->assertSame(2, $slot['remaining']);
        $this->actingAs($admin)->postJson(self::API.'/slots', ['start_time' => '14:10', 'end_time' => '14:30'] + $body)->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/slots', ['slot_date' => '2020-01-01'] + $body)->assertStatus(422);

        $this->actingAs($admin)->postJson(self::API.'/slots/bulk', ['action' => 'unpublish', 'slot_ids' => [$slot['id']]])->assertOk()->assertJsonPath('changed', 1);
        $this->book($this->patientUser()[0], $slot['id'])->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/slots/bulk', ['action' => 'publish', 'slot_ids' => [$slot['id']]])->assertOk();
        $this->actingAs($admin)->postJson(self::API.'/slots/bulk', ['action' => 'block', 'slot_ids' => [$slot['id']], 'reason' => 'Maintenance'])->assertOk();
        $this->book($this->patientUser()[0], $slot['id'])->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/slots/bulk', ['action' => 'unblock', 'slot_ids' => [$slot['id']]])->assertOk();
        $this->book($this->patientUser()[0], $slot['id'])->assertCreated();
        $this->actingAs($admin)->deleteJson(self::API.'/slots/'.$slot['id'])->assertStatus(409);
        $free = $this->slotAt($when->copy()->addHour());
        $this->actingAs($admin)->deleteJson(self::API."/slots/$free")->assertStatus(204);
        $this->actingAs($admin)->postJson(self::API.'/slots/bulk', ['action' => 'block'])->assertStatus(422);
    }

    // ---------- booking ----------

    public function test_patient_books_and_the_seat_is_taken(): void
    {
        [$u1, $p1] = $this->patientUser();
        [$u2] = $this->patientUser();
        $slot = $this->slotAt($this->future());

        $res = $this->book($u1, $slot, ['reason' => 'Chest pain follow-up'])->assertCreated();
        $this->assertMatchesRegularExpression('/^APT-\d{6}-\d{5}$/', $res->json('appointment_number'));
        $this->assertSame('confirmed', $res->json('status'));
        $this->assertSame($p1, $res->json('patient_id'));
        $this->assertSame('online', $res->json('booking_source'));
        $this->assertSame(1, DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
        $this->assertSame(1, DB::table('appointment_status_history')->count());
        $this->assertDatabaseHas('hms_notifications', ['event' => 'appointment_booked', 'recipient' => "pat{$u1->id}@example.com", 'status' => 'queued']);

        // Capacity 1: the next person is told the slot is gone, and nothing is overbooked.
        $this->book($u2, $slot)->assertStatus(409)->assertJsonFragment(['detail' => 'This slot has just been taken. Please choose another time.']);
        $this->assertSame(1, DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
        $this->assertSame(1, DB::table('appointments')->count());
    }

    public function test_capacity_greater_than_one_and_duplicate_prevention(): void
    {
        [$u1] = $this->patientUser();
        [$u2] = $this->patientUser();
        [$u3] = $this->patientUser();
        $slot = $this->slotAt($this->future(), 2);
        $this->book($u1, $slot)->assertCreated();
        $this->book($u1, $slot)->assertStatus(409); // same patient, same slot
        $this->book($u2, $slot)->assertCreated();
        $this->book($u3, $slot)->assertStatus(409);
        $this->assertSame(2, DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
    }

    public function test_same_patient_cannot_hold_overlapping_appointments(): void
    {
        [$u] = $this->patientUser();
        $when = $this->future();
        $a = $this->slotAt($when);
        $other = $this->doctor([$this->dept['id']]);
        $b = DB::table('appointment_slots')->insertGetId(['doctor_id' => $other['id'], 'department_id' => $this->dept['id'], 'slot_date' => $when->toDateString(), 'start_time' => $when->format('H:i:s'),
            'end_time' => $when->copy()->addMinutes(15)->format('H:i:s'), 'capacity' => 1, 'consultation_fee' => 500, 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->slotAt($when->copy()->addMinutes(15));
        $this->book($u, $a)->assertCreated();
        $this->book($u, $b)->assertStatus(409)->assertJsonFragment(['detail' => 'This patient already has an appointment at that time']);
        $this->book($u, $c)->assertCreated(); // back to back is fine
    }

    public function test_booking_rules_block_unavailable_slots(): void
    {
        [$u] = $this->patientUser();
        $this->book($u, $this->slotAt($this->future(), 1, ['is_published' => false]))->assertStatus(409);
        $this->book($u, $this->slotAt($this->future(3, '11:00'), 1, ['status' => 'blocked']))->assertStatus(409);
        $this->book($u, $this->slotAt(Hospital::now()->subDay()))->assertStatus(409);
        $this->book($u, $this->slotAt(Hospital::now()->addMinutes(10)))->assertStatus(409); // inside the 30-minute lead time
        $this->book($u, $this->slotAt($this->future(60)))->assertStatus(409); // beyond the 30-day window
        $holiday = $this->future(6);
        DB::table('holidays')->insert(['holiday_date' => $holiday->toDateString(), 'name' => 'H', 'created_at' => now(), 'updated_at' => now()]);
        $this->book($u, $this->slotAt($holiday))->assertStatus(409);
        $this->book($u, 999999)->assertStatus(404);
        $this->actingAs($this->root())->patchJson(self::API."/doctors/{$this->doc['id']}/status", ['status' => 'on_leave'])->assertOk();
        $this->book($u, $this->slotAt($this->future(7)))->assertStatus(409);
        $this->assertSame(0, DB::table('appointments')->count());
    }

    public function test_reception_books_for_a_patient_and_patients_cannot_book_for_others(): void
    {
        $desk = $this->user('receptionist');
        $pid = $this->patientRow();
        $slot = $this->slotAt($this->future());
        $this->actingAs($desk)->postJson(self::API.'/appointments', ['slot_id' => $slot])->assertStatus(422);
        $res = $this->actingAs($desk)->postJson(self::API.'/appointments', ['slot_id' => $slot, 'patient_id' => $pid])->assertCreated();
        $this->assertSame('reception', $res->json('booking_source'));

        [$u] = $this->patientUser();
        $other = $this->slotAt($this->future(4));
        $booked = $this->actingAs($u)->postJson(self::API.'/appointments', ['slot_id' => $other, 'patient_id' => $pid])->assertCreated();
        $this->assertNotSame($pid, $booked->json('patient_id')); // patient_id from a patient is ignored
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/appointments', ['slot_id' => $slot])->assertStatus(403);
    }

    // ---------- cancel & reschedule ----------

    public function test_patient_cancel_frees_the_seat_and_respects_the_cutoff(): void
    {
        [$u] = $this->patientUser();
        $slot = $this->slotAt($this->future());
        $id = $this->book($u, $slot)->json('id');
        $this->actingAs($u)->postJson(self::API."/appointments/$id/cancel", ['reason' => 'Travelling'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
        $this->assertNull(DB::table('appointments')->where('id', $id)->value('active_slot_key'));
        $this->actingAs($u)->postJson(self::API."/appointments/$id/cancel")->assertStatus(409);
        $this->book($u, $slot)->assertCreated(); // the same patient can rebook the freed slot
        $this->assertDatabaseHas('hms_notifications', ['event' => 'appointment_cancelled']);

        // Inside the 4-hour cutoff patients are turned away; reception can still cancel.
        [$u2] = $this->patientUser();
        $soon = $this->slotAt(Hospital::now()->addMinutes(90));
        $soonId = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/appointments', ['slot_id' => $soon, 'patient_id' => DB::table('patients')->where('user_id', $u2->id)->value('id')])->assertCreated()->json('id');
        $this->actingAs($u2)->postJson(self::API."/appointments/$soonId/cancel")->assertStatus(409);
        $this->assertFalse($this->actingAs($u2)->getJson(self::API."/appointments/$soonId")->json('can_cancel'));
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/appointments/$soonId/cancel", ['reason' => 'Doctor unavailable'])->assertOk();
    }

    public function test_reschedule_moves_the_seat_links_history_and_limits_repeats(): void
    {
        [$u] = $this->patientUser();
        $slots = [$this->slotAt($this->future(3)), $this->slotAt($this->future(4)), $this->slotAt($this->future(5)), $this->slotAt($this->future(6))];
        $first = $this->book($u, $slots[0])->json('id');

        $second = $this->actingAs($u)->postJson(self::API."/appointments/$first/reschedule", ['slot_id' => $slots[1]])->assertOk();
        $this->assertSame($first, $second->json('rescheduled_from_id'));
        $this->assertSame(0, DB::table('appointment_slots')->where('id', $slots[0])->value('booked_count'));
        $this->assertSame(1, DB::table('appointment_slots')->where('id', $slots[1])->value('booked_count'));
        $this->assertSame('cancelled', DB::table('appointments')->where('id', $first)->value('status'));

        $third = $this->actingAs($u)->postJson(self::API.'/appointments/'.$second->json('id').'/reschedule', ['slot_id' => $slots[2]])->assertOk()->json('id');
        // Two reschedules is the default maximum for patients.
        $this->actingAs($u)->postJson(self::API."/appointments/$third/reschedule", ['slot_id' => $slots[3]])->assertStatus(409);
        $this->assertFalse($this->actingAs($u)->getJson(self::API."/appointments/$third")->json('can_reschedule'));
        // Reception is exempt from the patient limits.
        $this->actingAs($this->user('receptionist'))->postJson(self::API."/appointments/$third/reschedule", ['slot_id' => $slots[3]])->assertOk();

        // A full target slot leaves the original booking untouched.
        [$u2] = $this->patientUser();
        $mine = $this->book($u2, $this->slotAt($this->future(8)))->json('id');
        $full = $this->slotAt($this->future(9));
        $this->book($this->patientUser()[0], $full)->assertCreated();
        $this->actingAs($u2)->postJson(self::API."/appointments/$mine/reschedule", ['slot_id' => $full])->assertStatus(409);
        $this->assertSame('confirmed', DB::table('appointments')->where('id', $mine)->value('status'));
        $this->assertSame(1, DB::table('appointment_slots')->where('id', DB::table('appointments')->where('id', $mine)->value('slot_id'))->value('booked_count'));
    }

    // ---------- status flow and access ----------

    public function test_status_flow_queue_numbers_and_invalid_moves(): void
    {
        $desk = $this->user('receptionist');
        $doctorUser = User::find($this->doc['user_id']);
        $when = Hospital::now()->subMinutes(20); // already started, so check-in and no-show are possible
        $a = $this->appointment($this->doc, $this->patientRow(), 0, 'confirmed', $when->format('H:i:s'));
        $b = $this->appointment($this->doc, $this->patientRow(), 0, 'confirmed', $when->copy()->addMinutes(15)->format('H:i:s'));
        DB::table('appointments')->whereIn('id', [$a, $b])->update(['appointment_date' => $when->toDateString()]);

        $this->actingAs($desk)->postJson(self::API."/appointments/$a/status", ['status' => 'completed'])->assertStatus(409);
        $this->actingAs($desk)->postJson(self::API."/appointments/$b/status", ['status' => 'checked_in'])->assertOk()->assertJsonPath('queue_number', 1);
        $this->actingAs($desk)->postJson(self::API."/appointments/$a/status", ['status' => 'checked_in'])->assertOk()->assertJsonPath('queue_number', 2);
        $this->actingAs($this->user('nurse'))->postJson(self::API."/appointments/$a/status", ['status' => 'in_consultation'])->assertStatus(403);

        // The doctor works their own visits through to completion.
        $this->actingAs($doctorUser)->postJson(self::API."/appointments/$a/status", ['status' => 'in_consultation'])->assertOk();
        $done = $this->actingAs($doctorUser)->postJson(self::API."/appointments/$a/status", ['status' => 'completed'])->assertOk()->json();
        $this->assertSame('completed', $done['status']);
        $this->assertSame(['checked_in', 'in_consultation', 'completed'], array_column($this->actingAs($desk)->getJson(self::API."/appointments/$a")->json('history'), 'to_status'));
        $this->actingAs($desk)->postJson(self::API."/appointments/$a/cancel")->assertStatus(409);

        // No-show releases the seat but only once the visit time has begun.
        $future = $this->appointment($this->doc, $this->patientRow(), 4);
        $this->actingAs($desk)->postJson(self::API."/appointments/$future/status", ['status' => 'no_show'])->assertStatus(409);
        $this->actingAs($desk)->postJson(self::API."/appointments/$b/status", ['status' => 'no_show'])->assertOk();
        $this->assertNull(DB::table('appointments')->where('id', $b)->value('active_slot_key'));
    }

    public function test_listing_and_access_scopes(): void
    {
        [$u1, $p1] = $this->patientUser();
        [$u2] = $this->patientUser();
        $doctorUser = User::find($this->doc['user_id']);
        $otherDoc = $this->doctor([$this->dept['id']]);
        $mine = $this->book($u1, $this->slotAt($this->future()))->json('id');
        $theirs = $this->appointment($otherDoc, $this->patientRow(), 3, 'confirmed', '15:00:00');

        $list = $this->actingAs($u1)->getJson(self::API.'/appointments')->assertOk();
        $this->assertSame([$mine], array_column($list->json('items'), 'id'));
        $this->assertNull($list->json('items.0.patient_mobile'));
        $this->actingAs($u2)->getJson(self::API."/appointments/$mine")->assertStatus(404);
        $this->actingAs($u2)->postJson(self::API."/appointments/$mine/cancel")->assertStatus(403);
        $this->actingAs($u2)->postJson(self::API."/appointments/$mine/status", ['status' => 'completed'])->assertStatus(403);

        // Browsers send booleans as the strings true/false.
        $this->actingAs($u1)->getJson(self::API.'/appointments?upcoming=true')->assertOk()->assertJsonPath('total', 1);
        $this->getJson(self::API.'/public/doctors?featured=true')->assertOk();
        $this->assertSame([$mine], array_column($this->actingAs($doctorUser)->getJson(self::API.'/appointments')->json('items'), 'id'));
        $this->actingAs($doctorUser)->getJson(self::API."/appointments/$theirs")->assertStatus(404);
        $this->assertFalse($this->actingAs($doctorUser)->getJson(self::API."/appointments/$mine")->json('can_cancel'));

        $all = $this->actingAs($this->user('receptionist'))->getJson(self::API.'/appointments')->assertOk();
        $this->assertEqualsCanonicalizing([$mine, $theirs], array_column($all->json('items'), 'id'));
        $this->assertNotNull($all->json('items.0.patient_mobile'));
        $this->assertSame(1, $this->actingAs($this->root())->getJson(self::API."/appointments?doctor_id={$otherDoc['id']}")->json('total'));
        $this->assertSame(1, $this->actingAs($this->root())->getJson(self::API."/appointments?q=PAT-".sprintf('%06d', $p1))->json('total'));
        $this->actingAs($this->root())->getJson(self::API.'/appointments?status=bogus')->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/appointments')->assertStatus(401);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PaymentGateway;
use App\Support\Hospital;
use App\Support\SetupLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

/** Booking without an account, and the online advance that confirms every online booking. */
class GuestBookingTest extends HmsTestCase
{
    private array $dept;

    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('booking.advance_required', true);
        $this->dept = $this->department();
        $this->doc = $this->doctor([$this->dept['id']]);
    }

    private function slot(int $days = 3, string $time = '10:00', float $fee = 600): int
    {
        $start = Carbon::parse(Hospital::now()->addDays($days)->toDateString().' '.$time, Hospital::tz());

        return DB::table('appointment_slots')->insertGetId([
            'doctor_id' => $this->doc['id'], 'department_id' => $this->dept['id'], 'slot_date' => $start->toDateString(), 'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(15)->format('H:i:s'), 'capacity' => 1, 'booked_count' => 0, 'consultation_fee' => $fee,
            'status' => 'available', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(int $slot, array $o = []): array
    {
        return $o + ['slot_id' => $slot, 'full_name' => 'Asha Verma', 'mobile' => '9876501234', 'consent' => true];
    }

    /** What the payment window sends back once the customer has paid. */
    private function paid(array $payment, string $paymentId = 'pay_TEST1'): array
    {
        return ['order_id' => $payment['order_id'], 'payment_id' => $paymentId, 'signature' => $payment['sim_signature']];
    }

    public function test_booking_holds_the_time_and_texts_nothing_until_the_advance_is_paid(): void
    {
        $slot = $this->slot();
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($slot, ['reason' => 'Fever']))->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('payment_status', 'awaiting_payment')->assertJsonPath('payment_mode', 'advance_online')
            ->assertJsonPath('advance_amount', 300)->assertJsonPath('payment.amount', 30000)->assertJsonPath('payment.driver', 'simulate')->json();

        $this->assertSame(1, (int) DB::table('appointment_slots')->where('id', $slot)->value('booked_count')); // held
        $this->assertSame(0, DB::table('sms_messages')->count());

        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk()
            ->assertJsonPath('status', 'confirmed')->assertJsonPath('payment_status', 'advance_paid')->assertJsonPath('account_created', true);

        // only the confirmation is texted now; the password link waits until the hospital has completed the patient's details
        $texts = DB::table('sms_messages')->where('mobile', '9876501234')->orderBy('id')->get();
        $this->assertSame(['booking_confirmed'], $texts->pluck('template')->all());
        $this->assertStringContainsString('Advance Rs.300 paid. Balance Rs.300 payable at the hospital.', $texts[0]->body);

        // paying twice (browser + webhook) changes nothing and texts nothing more
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk();
        $this->assertSame(1, DB::table('sms_messages')->count());
        $this->assertSame(1, DB::table('appointments')->where('payment_reference', 'pay_TEST1')->count());
    }

    public function test_a_forged_payment_is_refused_and_the_booking_stays_unconfirmed(): void
    {
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertCreated()->json();
        $this->postJson(self::API.'/public/payments/verify', ['order_id' => $res['payment']['order_id'], 'payment_id' => 'pay_X', 'signature' => 'forged'])->assertStatus(422);
        $this->assertSame('pending', DB::table('appointments')->value('status'));
        $this->assertSame(0, DB::table('sms_messages')->count());
    }

    public function test_an_unpaid_hold_expires_and_frees_the_slot(): void
    {
        $slot = $this->slot();
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertCreated()->json();
        $this->assertSame(1, (int) DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));

        $this->travel(20)->minutes();
        $this->assertSame(1, \App\Services\BookingService::releaseExpiredHolds());
        $this->assertSame(0, (int) DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
        $this->assertDatabaseHas('appointments', ['appointment_number' => $res['appointment_number'], 'status' => 'cancelled', 'cancel_reason' => 'Advance payment not completed in time']);

        // the same visitor can try again with the abandoned account, and someone else can take the slot meanwhile
        $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertCreated();
        $this->assertSame(1, User::where('mobile', '9876501234')->count());
    }

    public function test_paying_after_the_hold_lapsed_reinstates_the_booking_if_the_time_is_free(): void
    {
        $slot = $this->slot();
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertCreated()->json();
        $this->travel(20)->minutes();
        \App\Services\BookingService::releaseExpiredHolds();
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame(1, (int) DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
    }

    public function test_paying_after_the_time_was_taken_is_flagged_for_a_refund(): void
    {
        $slot = $this->slot();
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertCreated()->json();
        $this->travel(20)->minutes();
        \App\Services\BookingService::releaseExpiredHolds();
        DB::table('appointment_slots')->where('id', $slot)->update(['booked_count' => 1]); // someone else got it
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertStatus(409);
        $this->assertDatabaseHas('appointments', ['appointment_number' => $res['appointment_number'], 'payment_status' => 'refund_due', 'status' => 'cancelled']);
    }

    public function test_a_visit_with_no_fee_needs_no_advance(): void
    {
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot(fee: 0)))->assertCreated()->assertJsonPath('status', 'confirmed')->assertJsonPath('payment', null)->json();
        $this->assertSame(['booking_confirmed'], DB::table('sms_messages')->orderBy('id')->pluck('template')->all());
        $this->assertNotEmpty($res['appointment_number']);
    }

    public function test_each_doctor_can_set_their_own_advance(): void
    {
        $own = $this->doctor([$this->dept['id']], ['advance_amount' => 150, 'consultation_fee' => 500])['id'] ?? null;
        $this->assertNotNull($own);
        $this->actingAs($this->root())->getJson(self::API."/doctors/$own")->assertOk()->assertJsonPath('advance_amount', '150.00');
        // more than the fee is refused; blank means the hospital default
        $this->actingAs($this->root())->postJson(self::API.'/doctors', $this->doctorPayload([$this->dept['id']], ['advance_amount' => 900, 'consultation_fee' => 500]))->assertStatus(422);
        $this->assertNull($this->doctor([$this->dept['id']], ['consultation_fee' => 800])['advance_amount']);

        // quick edit from the doctor's Details card
        $root = $this->actingAs($this->root());
        $root->patchJson(self::API."/doctors/$own/fees", ['consultation_fee' => 600, 'advance_amount' => 200])->assertOk()->assertJsonPath('consultation_fee', '600.00')->assertJsonPath('advance_amount', '200.00');
        $root->patchJson(self::API."/doctors/$own/fees", ['consultation_fee' => 600, 'advance_amount' => 700])->assertStatus(422);
        $root->patchJson(self::API."/doctors/$own/fees", ['consultation_fee' => 500, 'advance_amount' => 150])->assertOk();
        $this->actingAs($this->user('receptionist'))->patchJson(self::API."/doctors/$own/fees", ['consultation_fee' => 1, 'advance_amount' => 1])->assertStatus(403);

        $this->doc = ['id' => $own];
        $this->postJson(self::API.'/public/bookings', $this->payload($this->slot(fee: 500)))->assertCreated()->assertJsonPath('advance_amount', 150)->assertJsonPath('payment.amount', 15000);
        $this->assertEquals(150, $this->getJson(self::API."/public/doctors/$own")->assertOk()->json('advance_amount'));
        $this->setSetting('booking.advance_required', false);
        $this->assertEquals(0, $this->getJson(self::API."/public/doctors/$own")->assertOk()->json('advance_amount'));
    }

    public function test_the_advance_never_exceeds_the_fee_and_follows_the_setting(): void
    {
        $this->postJson(self::API.'/public/bookings', $this->payload($this->slot(fee: 200)))->assertCreated()->assertJsonPath('advance_amount', 200)->assertJsonPath('payment.amount', 20000);
        $this->setSetting('booking.advance_amount', 100);
        $this->postJson(self::API.'/public/bookings', $this->payload($this->slot(4), ['mobile' => '9876501299']))->assertCreated()->assertJsonPath('advance_amount', 100);
        $this->getJson(self::API.'/public/booking-options')->assertOk()->assertJsonPath('advance_amount', 100)->assertJsonPath('advance_required', true);
    }

    public function test_an_online_patient_cannot_skip_the_advance(): void
    {
        $u = $this->user('patient', ['last_login_at' => now()]);
        $this->patientRow(['user_id' => $u->id, 'mobile' => '9123456780', 'email' => 'p@example.com']);
        $this->actingAs($u)->postJson(self::API.'/appointments', ['slot_id' => $this->slot(), 'payment_mode' => 'pay_at_hospital'])->assertStatus(422);
        $res = $this->actingAs($u)->postJson(self::API.'/appointments', ['slot_id' => $this->slot(4)])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('payment.amount', 30000)->json();
        $this->assertSame(0, DB::table('sms_messages')->count());
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk();
        $this->assertSame(['booking_confirmed'], DB::table('sms_messages')->pluck('template')->all()); // she already has a password: no setup link
    }

    public function test_front_desk_bookings_are_paid_at_the_counter(): void
    {
        $pid = $this->patientRow(['mobile' => '9123456780']);
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/appointments', ['slot_id' => $this->slot(), 'patient_id' => $pid])->assertCreated()
            ->assertJsonPath('status', 'confirmed')->assertJsonPath('payment_mode', 'pay_at_hospital')->assertJsonMissingPath('payment');
        $this->assertStringContainsString('pay Rs.600 at the hospital counter', DB::table('sms_messages')->value('body'));
    }

    public function test_staff_complete_the_details_and_the_patient_is_texted_a_password_link(): void
    {
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertCreated()->json();
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk();
        $user = User::where('mobile', '9876501234')->firstOrFail();
        $pid = (int) DB::table('patients')->where('user_id', $user->id)->value('id');
        $this->assertNull(DB::table('patients')->where('id', $pid)->value('date_of_birth'));

        $desk = $this->user('receptionist');
        $this->actingAs($desk)->getJson(self::API.'/patients?incomplete=1')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.details_pending', true)->assertJsonPath('items.0.age', null);
        $this->actingAs($desk)->getJson(self::API."/patients/$pid")->assertOk()->assertJsonPath('details_pending', true)->assertJsonPath('password_pending', true);

        // saving without a date of birth does not complete them
        $full = ['full_name' => 'Asha Verma', 'mobile' => '9876501234', 'date_of_birth' => '1990-04-12', 'gender' => 'female', 'address' => '12 Park Road', 'city' => 'Cuttack'];
        $this->actingAs($desk)->putJson(self::API."/patients/$pid", array_diff_key($full, ['date_of_birth' => 1]))->assertStatus(422);
        $this->assertSame(0, DB::table('sms_messages')->where('template', 'account_created')->count());

        $this->actingAs($desk)->putJson(self::API."/patients/$pid", $full)->assertOk()->assertJsonPath('details_pending', false)->assertJsonPath('password_link_sent', true);
        $text = DB::table('sms_messages')->where('template', 'account_created')->first();
        $this->assertSame('9876501234', $text->mobile);
        $this->assertStringContainsString('/set-password?u='.$user->id, $text->body);
        $this->assertStringNotContainsStringIgnoringCase('password:', $text->body);
        $this->actingAs($desk)->getJson(self::API.'/patients?incomplete=1')->assertJsonCount(0, 'items');

        // editing again does not text again; the desk can resend on request
        $this->actingAs($desk)->putJson(self::API."/patients/$pid", $full)->assertOk()->assertJsonPath('password_link_sent', false);
        $this->actingAs($desk)->postJson(self::API."/patients/$pid/password-link")->assertOk();
        $this->assertSame(2, DB::table('sms_messages')->where('template', 'account_created')->count());
        $this->actingAs($this->user('nurse'))->postJson(self::API."/patients/$pid/password-link")->assertStatus(403);

        // once they have chosen a password there is nothing to resend
        parse_str(parse_url(DB::table('sms_messages')->where('template', 'account_created')->latest('id')->value('body') ? SetupLink::make($user->fresh()) : '', PHP_URL_QUERY), $q);
        $this->postJson(self::API.'/auth/set-password', $q + ['password' => 'Strong123'])->assertOk();
        $this->actingAs($desk)->postJson(self::API."/patients/$pid/password-link")->assertStatus(409);
    }

    public function test_an_existing_walk_in_with_the_same_mobile_and_name_is_linked_not_duplicated(): void
    {
        $walkIn = $this->patientRow(['full_name' => 'Asha Verma', 'mobile' => '9876501234']);
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertCreated()->json();
        $this->assertSame(1, DB::table('patients')->where('mobile', '9876501234')->count());
        $this->assertNotNull(DB::table('patients')->where('id', $walkIn)->value('user_id'));
        $this->assertNotEmpty($res['appointment_number']);
    }

    public function test_a_held_booking_cannot_be_confirmed_by_hand_and_the_patient_can_resume_payment(): void
    {
        $u = $this->user('patient', ['last_login_at' => now()]);
        $this->patientRow(['user_id' => $u->id, 'mobile' => '9123456780', 'email' => 'p@example.com']);
        $held = $this->actingAs($u)->postJson(self::API.'/appointments', ['slot_id' => $this->slot()])->assertCreated()->json();

        $this->actingAs($this->root())->postJson(self::API."/appointments/{$held['id']}/status", ['status' => 'confirmed'])->assertStatus(409);
        $this->actingAs($this->root())->postJson(self::API."/appointments/{$held['id']}/status", ['status' => 'checked_in'])->assertStatus(409);
        $this->actingAs($u)->postJson(self::API."/appointments/{$held['id']}/reschedule", ['slot_id' => $this->slot(5)])->assertStatus(409);
        $this->assertSame('pending', DB::table('appointments')->where('id', $held['id'])->value('status'));

        // the patient closed the payment window: reopen it, pay, and it is confirmed
        $again = $this->actingAs($u)->postJson(self::API."/appointments/{$held['id']}/pay")->assertOk()->assertJsonPath('order_id', $held['payment']['order_id'])->json();
        $this->postJson(self::API.'/public/payments/verify', $this->paid($again))->assertOk()->assertJsonPath('status', 'confirmed');
        $this->actingAs($u)->postJson(self::API."/appointments/{$held['id']}/pay")->assertStatus(409); // nothing left to pay
        $other = $this->user('patient');
        $this->patientRow(['user_id' => $other->id, 'mobile' => '9123456781', 'email' => 'q@example.com']);
        $this->actingAs($other)->postJson(self::API."/appointments/{$held['id']}/pay")->assertStatus(403);
    }

    public function test_the_texted_link_sets_a_password_once_and_signs_in(): void
    {
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertCreated()->json();
        $this->postJson(self::API.'/public/payments/verify', $this->paid($res['payment']))->assertOk();
        $user = User::where('mobile', '9876501234')->firstOrFail();
        parse_str(parse_url(SetupLink::make($user), PHP_URL_QUERY), $q);

        $this->postJson(self::API.'/auth/set-password', $q + ['password' => 'weak'])->assertStatus(422);
        $this->postJson(self::API.'/auth/set-password', ['s' => 'tampered'] + $q + ['password' => 'Strong123'])->assertStatus(410);
        $this->postJson(self::API.'/auth/set-password', $q + ['password' => 'Strong123'])->assertOk()->assertJsonPath('user.mobile', '9876501234');
        $this->postJson(self::API.'/auth/set-password', $q + ['password' => 'Another123'])->assertStatus(410); // single use
        $this->postJson(self::API.'/auth/login', ['identifier' => '9876501234', 'password' => 'Strong123'])->assertOk();
    }

    public function test_a_number_with_a_working_account_must_sign_in(): void
    {
        $this->user('patient', ['mobile' => '9876501234', 'last_login_at' => now()]);
        $before = DB::table('appointments')->count();
        $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertStatus(409)->assertJsonPath('code', 'account_exists');
        $this->assertSame($before, DB::table('appointments')->count());
    }

    public function test_a_taken_slot_creates_no_account(): void
    {
        $slot = $this->slot();
        DB::table('appointment_slots')->where('id', $slot)->update(['booked_count' => 1]);
        $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertStatus(409);
        $this->assertNull(User::where('mobile', '9876501234')->first());
    }

    public function test_input_is_validated(): void
    {
        $slot = $this->slot();
        $this->postJson(self::API.'/public/bookings', $this->payload($slot, ['mobile' => '12345']))->assertStatus(422);
        $this->postJson(self::API.'/public/bookings', $this->payload($slot, ['consent' => false]))->assertStatus(422);
        $this->postJson(self::API.'/public/bookings', $this->payload($slot, ['payment_mode' => 'cash']))->assertStatus(422);
        $this->postJson(self::API.'/public/bookings', $this->payload($slot, ['mobile' => '+91 98765-01234']))->assertCreated();
    }

    public function test_a_gateway_failure_releases_the_hold_and_the_booking_fails_cleanly(): void
    {
        config(['payments.driver' => 'razorpay', 'payments.razorpay.key_id' => null]); // not configured -> creating the order fails
        $slot = $this->slot();
        $this->postJson(self::API.'/public/bookings', $this->payload($slot))->assertStatus(502);
        $this->assertSame(0, (int) DB::table('appointment_slots')->where('id', $slot)->value('booked_count'));
    }

    public function test_the_razorpay_webhook_confirms_a_payment_and_rejects_bad_signatures(): void
    {
        $res = $this->postJson(self::API.'/public/bookings', $this->payload($this->slot()))->assertCreated()->json();
        config(['payments.driver' => 'razorpay', 'payments.razorpay.webhook_secret' => 'whsec_test']);
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_WH1', 'order_id' => $res['payment']['order_id']]]]]);

        $this->call('POST', self::API.'/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'bad'], $body)->assertStatus(400);
        $this->assertSame('pending', DB::table('appointments')->value('status'));
        $sig = hash_hmac('sha256', $body, 'whsec_test');
        $this->call('POST', self::API.'/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertDatabaseHas('appointments', ['payment_reference' => 'pay_WH1', 'status' => 'confirmed']);
    }

    public function test_razorpay_signatures_follow_the_published_formula(): void
    {
        config(['payments.driver' => 'razorpay', 'payments.razorpay.key_secret' => 'secret']);
        $sig = hash_hmac('sha256', 'order_1|pay_1', 'secret');
        $this->assertTrue(PaymentGateway::verifyPayment('order_1', 'pay_1', $sig));
        $this->assertFalse(PaymentGateway::verifyPayment('order_1', 'pay_2', $sig));
    }
}

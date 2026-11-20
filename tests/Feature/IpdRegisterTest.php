<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class IpdRegisterTest extends HmsTestCase
{
    private function headId(string $code): int
    {
        return (int) DB::table('ipd_heads')->where('code', $code)->value('id');
    }

    private function admit(array $o = []): array
    {
        // A bed holds one admitted patient at a time, so the default is the first free G-bed.
        $taken = DB::table('ipd_admissions')->where('status', 'admitted')->whereNull('deleted_at')->pluck('bed')->all();
        $free = collect(range(1, 30))->map(fn ($n) => "G-$n")->first(fn ($b) => ! in_array($b, $taken, true));

        return $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', $o + [
            'admission_date' => '2026-11-01', 'patient_name' => 'SUNANDA PRADHAN', 'bed' => $free, 'case_title' => 'LSCS', 'doctors' => 'DR A ROUT, DR K PARIJA',
        ])->assertCreated()->json();
    }

    private function entry(int $admissionId, string $head, string $date, float $amount, ?string $note = null): array
    {
        return $this->actingAs($this->user('accountant'))->postJson(self::API."/ipd/admissions/$admissionId/entries", [
            'head_id' => $this->headId($head), 'entry_date' => $date, 'amount' => $amount, 'note' => $note,
        ])->assertCreated()->json();
    }

    public function test_access_is_limited_to_ipd_roles(): void
    {
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/ipd/admissions')->assertStatus(403);
        $this->actingAs($this->user('doctor'))->getJson(self::API.'/ipd/summary')->assertStatus(403);
        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/ipd/admissions')->assertOk();
        $this->actingAs($this->user('accountant'))->getJson(self::API.'/ipd/summary')->assertOk();
        $this->actingAs($this->root())->getJson(self::API.'/ipd/heads')->assertOk()->assertJsonCount(13);
    }

    public function test_anaesthetist_is_stored_and_balance_is_received_minus_paid_out(): void
    {
        $a = $this->admit(['anaesthetist' => 'DR S DAS']);
        $this->assertSame('DR S DAS', $a['anaesthetist']);
        $this->entry($a['id'], 'cash', '2026-11-02', 19000);
        $this->entry($a['id'], 'doctor_fee', '2026-11-03', 7000);
        $this->entry($a['id'], 'xray', '2026-11-03', 500);

        $row = $this->actingAs($this->root())->getJson(self::API.'/ipd/admissions?month=2026-11&q=DAS')->assertOk()->json('items.0');
        $this->assertSame('DR S DAS', $row['anaesthetist']);
        $this->assertEquals(12000, $row['balance']);
        $this->actingAs($this->root())->getJson(self::API.'/ipd/days?month=2026-11')->assertOk()->assertJsonPath('days.0.admissions.0.anaesthetist', 'DR S DAS');
    }

    public function test_admission_links_registered_doctors_department_and_bed(): void
    {
        $root = $this->root();
        $dept = $this->department('Gynaecology');
        $surgeon = $this->doctor([$dept['id']], ['full_name' => 'Dr Rout']);
        $anaes = $this->doctor([$dept['id']], ['full_name' => 'Dr Das']);
        $bed = $this->actingAs($root)->postJson(self::API.'/ipd/beds', ['code' => 'p-6', 'ward' => 'Private', 'department_id' => $dept['id']])->assertCreated()->assertJsonPath('code', 'P-6')->json();
        $this->actingAs($root)->postJson(self::API.'/ipd/beds', ['code' => 'P-6'])->assertStatus(409);

        $lookups = $this->actingAs($this->user('receptionist'))->getJson(self::API.'/ipd/lookups')->assertOk()->json();
        $this->assertContains('Dr Rout', array_column($lookups['doctors'], 'name'));
        $this->assertContains('Gynaecology', array_column($lookups['departments'], 'name'));

        $a = $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', [
            'admission_date' => '2026-11-01', 'patient_name' => 'LINKED PERSON', 'bed_id' => $bed['id'], 'department_id' => $dept['id'],
            'doctor_ids' => [$surgeon['id']], 'doctors' => 'DR VISITING', 'anaesthetist_id' => $anaes['id'],
        ])->assertCreated()->json();
        $this->assertSame('P-6', $a['bed']);
        $this->assertSame('Gynaecology', $a['department']);
        $this->assertSame('Dr Rout, DR VISITING', $a['doctors']);
        $this->assertSame('Dr Das', $a['anaesthetist']);
        $this->assertSame([$surgeon['id']], $a['doctor_ids']);

        // search finds the admission by a linked doctor's name or the department
        $this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-11&q=Rout')->assertOk()->assertJsonCount(1, 'items');
        $this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-11&q=Gynae')->assertOk()->assertJsonCount(1, 'items');

        // one patient per bed while admitted; discharge frees it
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_name' => 'SECOND', 'bed_id' => $bed['id']])->assertStatus(409);
        $this->actingAs($root)->putJson(self::API."/ipd/beds/{$bed['id']}", ['code' => 'P-6', 'is_active' => false])->assertStatus(409);
        $this->actingAs($root)->putJson(self::API."/ipd/admissions/{$a['id']}", ['admission_date' => '2026-11-01', 'patient_name' => 'LINKED PERSON', 'bed_id' => $bed['id'], 'status' => 'discharged'])->assertOk();
        $this->actingAs($this->user('receptionist'))->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_name' => 'SECOND', 'bed_id' => $bed['id']])->assertCreated();

        $this->actingAs($root)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_name' => 'XY', 'doctor_ids' => [999999]])->assertStatus(400);
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/ipd/beds')->assertStatus(403);
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/ipd/beds', ['code' => 'Z-1'])->assertStatus(403);
    }

    public function test_receipts_record_a_payment_mode_and_receipt_number(): void
    {
        $a = $this->admit();
        $viaHead = $this->entry($a['id'], 'upi', '2026-11-02', 500);
        $this->assertSame('upi', collect($viaHead['entries'])->first()['payment_mode']);

        $res = $this->actingAs($this->user('accountant'))->postJson(self::API."/ipd/admissions/{$a['id']}/entries", [
            'head_id' => $this->headId('collection'), 'entry_date' => '2026-11-03', 'amount' => 300, 'payment_mode' => 'cheque', 'receipt_no' => 'R-1042',
        ])->assertCreated()->json('entries');
        $this->assertSame(['cheque', 'R-1042'], [$res[1]['payment_mode'], $res[1]['receipt_no']]);
        // payouts and billed services are not payments, so they carry no mode
        $out = $this->entry($a['id'], 'doctor_fee', '2026-11-03', 100);
        $this->assertNull(collect($out['entries'])->last()['payment_mode']);
        $this->actingAs($this->user('accountant'))->postJson(self::API."/ipd/admissions/{$a['id']}/entries", ['head_id' => $this->headId('cash'), 'entry_date' => '2026-11-03', 'amount' => 1, 'payment_mode' => 'bitcoin'])->assertStatus(422);
    }

    public function test_every_in_patient_is_a_registered_patient(): void
    {
        $desk = $this->user('receptionist');
        $a = $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-01', 'patient_name' => 'Ramesh Das', 'patient_mobile' => '9876500001'])->assertCreated()->json();
        $this->assertNotNull($a['patient_id']);
        $this->assertDatabaseHas('patients', ['id' => $a['patient_id'], 'full_name' => 'Ramesh Das', 'mobile' => '9876500001', 'date_of_birth' => null]);

        // they appear on the Patients page, flagged until staff complete the details
        $list = $this->actingAs($this->root())->getJson(self::API.'/patients?incomplete=1')->assertOk()->json('items');
        $this->assertContains('Ramesh Das', array_column($list, 'full_name'));

        // editing the admission keeps the same person; a mobile is optional (the paper register had none)
        $this->actingAs($desk)->putJson(self::API."/ipd/admissions/{$a['id']}", ['admission_date' => '2026-11-01', 'patient_name' => 'ramesh das', 'case_title' => 'LSCS'])->assertOk()->assertJsonPath('patient_id', $a['patient_id']);
        $b = $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_name' => 'No Mobile Person'])->assertCreated()->json();
        $this->assertNull(DB::table('patients')->where('id', $b['patient_id'])->value('mobile'));
        $this->assertSame(2, DB::table('patients')->count());

        // choosing a registered patient uses their name and creates nobody new
        $pid = $this->patientRow(['full_name' => 'Registered Person']);
        $c = $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-03', 'patient_id' => $pid, 'patient_name' => 'typed over'])->assertCreated()->json();
        $this->assertSame('Registered Person', $c['patient_name']);
        $this->assertSame($pid, $c['patient_id']);
        $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-03', 'patient_name' => 'Bad Mobile', 'patient_mobile' => '123'])->assertStatus(422);
    }

    public function test_doctors_are_linked_both_ways(): void
    {
        $dept = $this->department();
        $surgeon = $this->doctor([$dept['id']], ['full_name' => 'Dr Rout']);
        $anaes = $this->doctor([$dept['id']], ['full_name' => 'Dr Das']);
        $other = $this->doctor([$dept['id']], ['full_name' => 'Dr Unrelated']);
        $desk = $this->user('receptionist');
        $a = $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-01', 'patient_name' => 'Linked Patient', 'doctor_ids' => [$surgeon['id']], 'anaesthetist_id' => $anaes['id'], 'doctors' => 'DR VISITING'])
            ->assertCreated()->assertJsonPath('doctor_list.0.name', 'Dr Rout')->assertJsonPath('doctor_list.0.id', $surgeon['id'])->json();

        // day-wise rows carry the same links
        $day = $this->actingAs($desk)->getJson(self::API.'/ipd/days?month=2026-11')->assertOk()->json('days.0.admissions.0');
        $this->assertSame($surgeon['id'], $day['doctor_list'][0]['id']);
        $this->assertSame($anaes['id'], $day['anaesthetist_id']);

        // a doctor's record lists their cases, as surgeon or as anaesthetist, and nobody else's
        $this->actingAs($desk)->getJson(self::API."/ipd/admissions?doctor_id={$surgeon['id']}")->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $a['id']);
        $this->actingAs($desk)->getJson(self::API."/ipd/admissions?doctor_id={$anaes['id']}")->assertOk()->assertJsonCount(1, 'items');
        $this->actingAs($desk)->getJson(self::API."/ipd/admissions?doctor_id={$other['id']}")->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_columns_can_be_added_renamed_and_retyped(): void
    {
        $admin = $this->root();
        $new = $this->actingAs($admin)->postJson(self::API.'/ipd/heads', ['name' => 'Medicine', 'type' => 'charge'])->assertCreated()->json();
        $this->assertSame('medicine', $new['code']);
        $this->actingAs($admin)->postJson(self::API.'/ipd/heads', ['name' => 'medicine', 'type' => 'charge'])->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/ipd/heads', ['name' => 'Bad', 'type' => 'nonsense'])->assertStatus(422);
        $this->actingAs($admin)->putJson(self::API."/ipd/heads/{$new['id']}", ['name' => 'Pharmacy', 'type' => 'receipt', 'is_active' => true])->assertOk()->assertJsonPath('type', 'receipt');
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/ipd/heads', ['name' => 'X1', 'type' => 'charge'])->assertStatus(403);
    }

    public function test_admission_validation_and_discharge(): void
    {
        $desk = $this->user('receptionist');
        $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-01'])->assertStatus(422);
        $pid = $this->patientRow(['full_name' => 'Registered Person']);
        $a = $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_id' => $pid, 'bed' => 'P-6'])->assertCreated()->json();
        $this->assertSame('Registered Person', $a['patient_name']);
        $this->assertSame('admitted', $a['status']);

        $this->actingAs($desk)->putJson(self::API."/ipd/admissions/{$a['id']}", ['admission_date' => '2026-11-02', 'patient_id' => $pid, 'status' => 'discharged', 'discharge_date' => '2026-11-01'])->assertStatus(422);
        $done = $this->actingAs($desk)->putJson(self::API."/ipd/admissions/{$a['id']}", ['admission_date' => '2026-11-02', 'patient_id' => $pid, 'status' => 'discharged', 'discharge_date' => '2026-11-05'])->assertOk()->json();
        $this->assertSame(['discharged', '2026-11-05'], [$done['status'], $done['discharge_date']]);
        $this->actingAs($desk)->postJson(self::API.'/ipd/admissions', ['admission_date' => '2026-11-02', 'patient_id' => 99999])->assertStatus(400);
    }

    public function test_entries_total_per_column_and_per_type_and_can_be_corrected(): void
    {
        $a = $this->admit();
        $this->entry($a['id'], 'cash', '2026-11-06', 19000);
        $this->entry($a['id'], 'cash', '2026-11-08', 1000, 'balance');
        $this->entry($a['id'], 'upi', '2026-11-01', 9500);
        $this->entry($a['id'], 'doctor_fee', '2026-11-07', 3500);
        $this->entry($a['id'], 'anaesthesia', '2026-11-07', 2000);
        $this->entry($a['id'], 'xray', '2026-11-03', 700);

        $row = $this->actingAs($this->root())->getJson(self::API."/ipd/admissions/{$a['id']}")->assertOk()->json();
        $this->assertEquals(20000, $row['totals'][$this->headId('cash')]);
        $this->assertEquals(29500, $row['received']);
        $this->assertEquals(5500, $row['payouts']);
        $this->assertEquals(700, $row['charges']);
        $this->assertCount(6, $row['entries']);

        $first = $row['entries'][0];
        $this->actingAs($this->root())->putJson(self::API."/ipd/entries/{$first['id']}", ['head_id' => $this->headId('card'), 'entry_date' => '2026-11-01', 'amount' => 9000])->assertOk();
        $this->actingAs($this->root())->deleteJson(self::API."/ipd/entries/{$first['id']}")->assertStatus(204);
        $this->assertCount(5, $this->actingAs($this->root())->getJson(self::API."/ipd/admissions/{$a['id']}")->json('entries'));

        $bad = ['head_id' => $this->headId('cash'), 'entry_date' => '2026-11-06'];
        $this->actingAs($this->root())->postJson(self::API."/ipd/admissions/{$a['id']}/entries", $bad + ['amount' => 0])->assertStatus(422);
        $this->actingAs($this->root())->postJson(self::API."/ipd/admissions/{$a['id']}/entries", $bad + ['amount' => -5])->assertStatus(422);
        $this->actingAs($this->root())->postJson(self::API."/ipd/admissions/{$a['id']}/entries", ['head_id' => 9999, 'amount' => 5] + $bad)->assertStatus(400);
        $this->actingAs($this->user('nurse'))->postJson(self::API."/ipd/admissions/{$a['id']}/entries", $bad + ['amount' => 5])->assertStatus(403);
    }

    public function test_monthly_register_summary_and_search(): void
    {
        $root = $this->root();
        $a = $this->admit(['admission_date' => '2026-11-01', 'patient_name' => 'SUNANDA PRADHAN', 'case_title' => 'LSCS']);
        $b = $this->admit(['admission_date' => '2026-11-03', 'patient_name' => 'RUNI PRADHAN', 'case_title' => 'ORTHO', 'bed' => 'D-3']);
        $oct = $this->admit(['admission_date' => '2026-10-28', 'patient_name' => 'EARLIER PATIENT']);
        $other = $this->admit(['admission_date' => '2026-09-01', 'patient_name' => 'OLD PATIENT']);
        $this->entry($a['id'], 'cash', '2026-11-06', 19000);
        $this->entry($a['id'], 'doctor_fee', '2026-11-07', 3500);
        $this->entry($b['id'], 'upi', '2026-11-05', 5000);
        $this->entry($oct['id'], 'cash', '2026-11-02', 4000); // admitted in October, paid in November
        $this->entry($other['id'], 'cash', '2026-10-05', 999); // entirely outside the month

        $list = $this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-11')->assertOk();
        $this->assertSame(['EARLIER PATIENT', 'SUNANDA PRADHAN', 'RUNI PRADHAN'], array_column($list->json('items'), 'patient_name'));
        $this->assertCount(3, $list->json('items'));
        $this->assertSame(1, count($this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-11&q=ORTHO')->json('items')));
        $this->assertSame(1, count($this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-11&q=D-3')->json('items')));
        $this->actingAs($root)->getJson(self::API.'/ipd/admissions?month=2026-13')->assertStatus(422);

        $s = $this->actingAs($root)->getJson(self::API.'/ipd/summary?month=2026-11')->assertOk()->json();
        $this->assertEquals(28000, $s['received']); // 19000 + 5000 + 4000
        $this->assertEquals(3500, $s['payouts']);
        $this->assertEquals(24500, $s['net']);
        $this->assertSame(2, $s['admissions_in_month']);
        $this->assertSame(4, $s['currently_admitted']);
        $cash = collect($s['heads'])->firstWhere('code', 'cash');
        $this->assertEquals(23000, $cash['total']);
        $this->assertSame(2, $cash['entries']);
        $this->assertEquals(19000, collect($s['daily'])->firstWhere('date', '2026-11-06')['received']);
        $this->assertCount(13, $s['heads']);
    }

    public function test_deleting_an_admission_hides_it_but_keeps_entries_and_is_audited_without_names(): void
    {
        $a = $this->admit(['patient_name' => 'PRIVATE NAME PERSON']);
        $this->entry($a['id'], 'cash', '2026-11-06', 100);
        $this->actingAs($this->root())->deleteJson(self::API."/ipd/admissions/{$a['id']}")->assertStatus(204);
        $this->actingAs($this->root())->getJson(self::API."/ipd/admissions/{$a['id']}")->assertStatus(404);
        $this->assertSame(0, count($this->actingAs($this->root())->getJson(self::API.'/ipd/admissions?month=2026-11')->json('items')));
        $this->assertDatabaseCount('ipd_entries', 1);
        foreach (DB::table('audit_logs')->where('entity_type', 'like', 'ipd_%')->pluck('changes') as $c) {
            $this->assertStringNotContainsString('PRIVATE NAME', (string) $c);
        }
    }

    public function test_day_wise_view_groups_admissions_and_money_by_date(): void
    {
        $a = $this->admit(['admission_date' => '2026-11-02', 'patient_name' => 'DAY ONE PERSON']);
        $b = $this->admit(['admission_date' => '2026-11-02', 'patient_name' => 'DAY ONE SECOND']);
        $this->entry($a['id'], 'cash', '2026-11-06', 19000);
        $this->entry($b['id'], 'upi', '2026-11-06', 1000);
        $this->entry($a['id'], 'doctor_fee', '2026-11-06', 3500);
        $this->entry($a['id'], 'xray', '2026-11-08', 700);

        $res = $this->actingAs($this->root())->getJson(self::API.'/ipd/days?month=2026-11')->assertOk()->json();
        $days = collect($res['days'])->keyBy('date');
        $this->assertSame(['2026-11-02', '2026-11-06', '2026-11-08'], array_keys($days->all()));
        $this->assertCount(2, $days['2026-11-02']['admissions']);
        $this->assertEquals(0, $days['2026-11-02']['received']);
        $this->assertCount(3, $days['2026-11-06']['entries']);
        $this->assertEquals(20000, $days['2026-11-06']['received']);
        $this->assertEquals(3500, $days['2026-11-06']['payouts']);
        $this->assertEquals(700, $days['2026-11-08']['charges']);
        $this->assertSame('DAY ONE PERSON', $days['2026-11-06']['entries'][0]['patient_name']);
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/ipd/days')->assertStatus(403);
    }

    public function test_admissions_link_to_a_patient_record_and_can_be_listed_per_patient(): void
    {
        $pid = $this->patientRow(['full_name' => 'Linked Person']);
        $other = $this->patientRow();
        $a = $this->admit(['patient_id' => $pid, 'patient_name' => null, 'admission_date' => '2026-10-10']);
        $this->admit(['patient_id' => $pid, 'admission_date' => '2026-11-10']);
        $this->admit(['patient_id' => $other, 'admission_date' => '2026-11-11']);
        $this->entry($a['id'], 'cash', '2026-10-12', 500);

        $res = $this->actingAs($this->root())->getJson(self::API."/ipd/admissions?patient_id=$pid")->assertOk();
        $this->assertCount(2, $res->json('items'));
        $this->assertSame('2026-11-10', $res->json('items.0.admission_date')); // newest first, any month
        $this->assertSame($pid, $res->json('items.1.patient_id'));
        $this->assertNotNull($res->json('items.1.patient_code'));
        $this->assertEquals(500, $res->json('items.1.received'));
        $this->actingAs($this->user('nurse'))->getJson(self::API."/ipd/admissions?patient_id=$pid")->assertStatus(403);
    }

    public function test_a_date_range_filters_every_view(): void
    {
        $a = $this->admit(['admission_date' => '2026-11-03', 'patient_name' => 'RANGE ONE']);
        $b = $this->admit(['admission_date' => '2026-11-20', 'patient_name' => 'RANGE TWO']);
        $this->entry($a['id'], 'cash', '2026-11-04', 1000);
        $this->entry($b['id'], 'cash', '2026-11-21', 2000);
        $root = $this->root();

        $range = '?from=2026-11-01&to=2026-11-10';
        $this->assertSame(['RANGE ONE'], array_column($this->actingAs($root)->getJson(self::API.'/ipd/admissions'.$range)->json('items'), 'patient_name'));
        $this->assertEquals(1000, $this->actingAs($root)->getJson(self::API.'/ipd/summary'.$range)->json('received'));
        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($this->actingAs($root)->getJson(self::API.'/ipd/days'.$range)->json('days'), 'date'));
        $single = '?from=2026-11-21&to=2026-11-21'; // one day
        $this->assertEquals(2000, $this->actingAs($root)->getJson(self::API.'/ipd/summary'.$single)->json('received'));
        $this->assertStringContainsString('ipd-register-2026-11-01_2026-11-10.csv', $this->actingAs($root)->get(self::API.'/ipd/export'.$range)->headers->get('Content-Disposition'));

        $this->actingAs($root)->getJson(self::API.'/ipd/summary?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->actingAs($root)->getJson(self::API.'/ipd/summary?from=2026-11-01')->assertStatus(422);
        $this->actingAs($root)->getJson(self::API.'/ipd/summary?from=2024-01-01&to=2026-11-01')->assertStatus(422);
    }

    public function test_csv_export_mirrors_the_spreadsheet(): void
    {
        $a = $this->admit();
        $this->entry($a['id'], 'cash', '2026-11-06', 19000);
        $this->entry($a['id'], 'cash', '2026-11-08', 1000);
        $res = $this->actingAs($this->root())->get(self::API.'/ipd/export?month=2026-11')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDate,Patient,Cash,UPI,Bed,Department,Case,Doctors,Anaesthetist,Status,Collection", $csv);
        $this->assertStringContainsString('01-11-2026,"SUNANDA PRADHAN","19000 (06.11); 1000 (08.11)",,G-1,,LSCS', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_type' => 'ipd_register']);
        $this->actingAs($this->user('nurse'))->get(self::API.'/ipd/export')->assertStatus(403);
    }
}

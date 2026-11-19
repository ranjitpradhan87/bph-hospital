<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\HmsTestCase;

class PatientsTest extends HmsTestCase
{
    private function payload(array $o = []): array
    {
        $n = $this->next();

        return $o + ['full_name' => "Desk Patient $n", 'mobile' => sprintf('95%08d', $n), 'email' => "desk$n@example.com", 'date_of_birth' => '1988-02-02',
            'gender' => 'female', 'blood_group' => 'O+', 'address' => '12 MG Road', 'consent' => true];
    }

    public function test_front_desk_registration_duplicates_and_search(): void
    {
        $desk = $this->user('receptionist');
        $payload = $this->payload(['email' => 'Desk.Patient@Example.com', 'pincode' => '560001']);
        $p = $this->actingAs($desk)->postJson(self::API.'/patients', $payload)->assertCreated()->json();
        $this->assertStringStartsWith('PAT-', $p['patient_code']);
        $this->assertFalse($p['has_login']);
        $this->assertSame('desk.patient@example.com', $p['email']);

        $dup = $this->actingAs($desk)->postJson(self::API.'/patients', ['full_name' => 'Someone Else'] + $payload)->assertStatus(409);
        $this->assertStringContainsString($p['patient_code'], $dup->json('detail'));
        $this->actingAs($desk)->postJson(self::API.'/patients', ['full_name' => 'Twin Sibling', 'allow_duplicate' => true] + $payload)->assertCreated();
        $this->actingAs($desk)->postJson(self::API.'/patients', $this->payload(['consent' => false]))->assertStatus(422);

        foreach ([$p['patient_code'], substr($p['mobile'], -6), 'desk.patient'] as $term) {
            $found = $this->actingAs($desk)->getJson(self::API.'/patients?search='.urlencode($term))->json('items');
            $this->assertContains($p['id'], array_column($found, 'id'), $term);
        }

        $edit = array_diff_key($payload, ['consent' => 1]);
        $edit['address'] = '4 New Street';
        $this->actingAs($desk)->putJson(self::API."/patients/{$p['id']}", $edit)->assertOk()->assertJsonPath('address', '4 New Street');
    }

    public function test_walk_in_is_linked_on_self_registration(): void
    {
        $desk = $this->user('receptionist');
        $walkIn = $this->actingAs($desk)->postJson(self::API.'/patients', $this->payload(['date_of_birth' => '1979-09-09']))->assertCreated()->json();
        $n = $this->next();
        $res = $this->postJson(self::API.'/auth/register', [
            'full_name' => $walkIn['full_name'], 'email' => "selfreg$n@example.com", 'mobile' => $walkIn['mobile'],
            'date_of_birth' => '1979-09-09', 'gender' => 'male', 'password' => 'Secret123', 'consent' => true,
        ])->assertCreated();
        $this->assertSame($walkIn['patient_code'], $res->json('user.patient_code'));
        $this->assertDatabaseCount('patients', 1);
        $this->actingAs($desk)->getJson(self::API."/patients/{$walkIn['id']}")->assertJsonPath('has_login', true);
    }

    public function test_doctor_sees_only_their_patients_and_record_visibility(): void
    {
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $mine = $this->patientRow();
        $other = $this->patientRow();
        $this->appointment($doc, $mine);
        $doctor = User::find($doc['user_id']);

        $listed = $this->actingAs($doctor)->getJson(self::API.'/patients?page_size=100')->json('items');
        $this->assertSame([$mine], array_column($listed, 'id'));
        $this->actingAs($doctor)->getJson(self::API."/patients/$other")->assertStatus(404);
        $this->actingAs($doctor)->getJson(self::API."/patients/$mine/appointments")->assertJsonPath('total', 1);

        $note = ['record_type' => 'note', 'title' => 'Sensitive history', 'content' => 'Discussed privately.', 'visibility' => 'doctor_only'];
        $this->actingAs($doctor)->postJson(self::API."/patients/$mine/medical-records", $note)->assertCreated();
        $allergy = ['record_type' => 'allergy', 'title' => 'Penicillin', 'content' => 'Rash in 2019'];
        $this->actingAs($doctor)->postJson(self::API."/patients/$mine/medical-records", $allergy)->assertCreated();
        $this->actingAs($doctor)->postJson(self::API."/patients/$other/medical-records", $allergy)->assertStatus(404);
        // clinical text is encrypted at rest
        $this->assertStringNotContainsString('Rash in 2019', (string) DB::table('medical_records')->where('title', 'Penicillin')->value('content_encrypted'));

        $nurse = $this->user('nurse');
        $titles = array_column($this->actingAs($nurse)->getJson(self::API."/patients/$mine/medical-records")->json(), 'title');
        $this->assertSame(['Penicillin'], $titles); // doctor-only note hidden
        $obs = ['record_type' => 'observation', 'title' => 'Vitals', 'data' => ['bp' => '120/80', 'pulse' => 72]];
        $this->actingAs($nurse)->postJson(self::API."/patients/$mine/medical-records", $obs)->assertCreated();
        $this->actingAs($nurse)->postJson(self::API."/patients/$mine/medical-records", $allergy)->assertStatus(403);
        $this->actingAs($nurse)->postJson(self::API."/patients/$mine/medical-records", ['data' => []] + $obs)->assertStatus(422);

        $this->actingAs($this->user('receptionist'))->getJson(self::API."/patients/$mine/medical-records")->assertStatus(403);
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('action', 'read')->where('entity_type', 'medical_records')->where('entity_id', (string) $mine)->count());
    }

    public function test_patient_cannot_read_other_patients_data(): void
    {
        $a = $this->postJson(self::API.'/auth/register', ['full_name' => 'Alpha One', 'email' => 'alpha@example.com', 'mobile' => '9811111111', 'date_of_birth' => '1990-01-01', 'gender' => 'male', 'password' => 'Secret123', 'consent' => true])->assertCreated();
        $other = $this->patientRow();
        $me = User::where('email', 'alpha@example.com')->first();
        $this->actingAs($me)->getJson(self::API."/patients/{$a->json('user.patient_id')}/appointments")->assertOk();
        $this->actingAs($me)->getJson(self::API."/patients/$other/appointments")->assertStatus(404);
    }

    public function test_document_upload_and_audited_download(): void
    {
        Storage::fake('local');
        $root = $this->root();
        $pid = $this->patientRow();
        $pdf = "%PDF-1.4\n%fake\n";
        $rec = $this->actingAs($root)->post(self::API."/patients/$pid/documents", [
            'title' => 'Blood report', 'file' => UploadedFile::fake()->createWithContent('../../etc/report.pdf', $pdf),
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('report.pdf', $rec['file_name']);
        $this->assertSame('application/pdf', $rec['mime_type']);

        $dl = $this->actingAs($root)->get(self::API."/medical-records/{$rec['id']}/file")->assertOk();
        $this->assertSame($pdf, file_get_contents($dl->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('attachment', $dl->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $dl->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'download', 'entity_id' => (string) $rec['id']]);

        foreach ([['run.exe', "MZ\x90\x00"], ['page.html', '<html><script>']] as [$name, $content]) {
            $this->actingAs($root)->post(self::API."/patients/$pid/documents", ['title' => 'Bad file', 'file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json'])->assertStatus(400);
        }
    }

    public function test_download_requires_login(): void
    {
        $this->getJson(self::API.'/medical-records/1/file')->assertStatus(401);
    }

    public function test_portal_profile_self_service(): void
    {
        $n = $this->next();
        $reg = $this->postJson(self::API.'/auth/register', ['full_name' => 'Portal Person', 'email' => "portal$n@example.com", 'mobile' => sprintf('96%08d', $n),
            'date_of_birth' => '1995-03-03', 'gender' => 'female', 'password' => 'Secret123', 'consent' => true])->assertCreated();
        $profile = $this->getJson(self::API.'/portal/profile')->assertOk()->json();
        $this->assertSame($reg->json('user.patient_code'), $profile['patient_code']);

        $body = $this->putJson(self::API.'/portal/profile', ['full_name' => 'Hacked Name', 'address' => '22 Lake View', 'pincode' => '560034',
            'emergency_contact_name' => 'Ravi', 'emergency_contact_phone' => '9876501234'])->assertOk()->json();
        $this->assertSame('22 Lake View', $body['address']);
        $this->assertSame('Portal Person', $body['full_name']); // name isn't self-editable

        $this->actingAs($this->user('receptionist'))->getJson(self::API.'/portal/profile')->assertStatus(403);
    }
}

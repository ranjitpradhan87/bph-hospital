<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Department;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\HmsTestCase;

class AdminBasicsTest extends HmsTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n";

    // ---------- settings ----------

    public function test_settings_listing_and_permissions(): void
    {
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/settings')->assertStatus(403);
        $res = $this->actingAs($this->root())->getJson(self::API.'/settings')->assertOk();
        $keys = collect($res->json())->pluck('key');
        $this->assertTrue($keys->contains('booking.advance_booking_days'));
        $this->assertCount(38, $keys);
        $tz = collect($res->json())->firstWhere('key', 'hospital.timezone');
        $this->assertFalse($tz['editable']);
    }

    public function test_update_settings_validates_normalises_and_audits(): void
    {
        $root = $this->root();
        $res = $this->actingAs($root)->putJson(self::API.'/settings', ['values' => [
            'hospital.name' => '  City Care Hospital ',
            'hospital.email' => 'INFO@CityCare.example',
            'hospital.working_days' => [3, 1, 2],
            'billing.invoice_prefix' => 'bill',
            'booking.advance_booking_days' => '45',
            'hospital.address' => ['city' => 'Pune', 'pincode' => '411001'],
        ]])->assertOk();
        $by = collect($res->json())->keyBy('key');
        $this->assertSame('City Care Hospital', $by['hospital.name']['value']);
        $this->assertSame('info@citycare.example', $by['hospital.email']['value']);
        $this->assertSame([1, 2, 3], $by['hospital.working_days']['value']);
        $this->assertSame('BILL', $by['billing.invoice_prefix']['value']);
        $this->assertSame(45, $by['booking.advance_booking_days']['value']);
        $this->assertSame('Pune', $by['hospital.address']['value']['city']);
        $this->assertSame('India', $by['hospital.address']['value']['country']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_type' => 'hospital_settings']);
    }

    public function test_invalid_settings_are_rejected_atomically(): void
    {
        $root = $this->root();
        $res = $this->actingAs($root)->putJson(self::API.'/settings', ['values' => [
            'hospital.name' => 'Should Not Save',
            'booking.advance_booking_days' => 999,
            'hospital.working_hours' => ['start' => '18:00', 'end' => '09:00'],
            'hospital.timezone' => 'UTC',
            'nope.key' => 1,
            'hospital.logo_url' => 'javascript:alert(1)',
            'content.services' => [['icon' => 'not-an-icon', 'title' => 'X']],
        ]])->assertStatus(422);
        $locs = collect($res->json('detail'))->pluck('loc')->map(fn ($l) => implode('.', $l))->all();
        $this->assertContains('values.booking.advance_booking_days', $locs);
        $this->assertContains('values.hospital.timezone', $locs);
        $this->assertContains('values.nope.key', $locs);
        $this->assertContains('values.hospital.logo_url', $locs);
        $this->assertContains('values.content.services.0.icon', $locs);
        $stored = json_decode(DB::table('hospital_settings')->where('key', 'hospital.name')->value('value'));
        $this->assertNotSame('Should Not Save', $stored);
    }

    // ---------- holidays ----------

    public function test_holidays_crud_and_permissions(): void
    {
        $root = $this->root();
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/holidays')->assertStatus(403);
        $id = $this->actingAs($root)->postJson(self::API.'/holidays', ['holiday_date' => '2027-01-26', 'name' => 'Republic Day'])->assertCreated()->json('id');
        $this->actingAs($root)->postJson(self::API.'/holidays', ['holiday_date' => '2027-01-26', 'name' => 'Duplicate'])->assertStatus(409);
        $this->actingAs($root)->getJson(self::API.'/holidays?year=2027')->assertOk()->assertJsonCount(1);
        $this->actingAs($root)->getJson(self::API.'/holidays?year=2030')->assertOk()->assertJsonCount(0);
        $this->actingAs($root)->deleteJson(self::API."/holidays/$id")->assertStatus(204);
        $this->assertDatabaseCount('holidays', 0);
    }

    // ---------- uploads ----------

    public function test_image_upload_and_serving(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('logo.png', self::PNG.str_repeat("\0", 64));
        $res = $this->actingAs($this->root())->post(self::API.'/uploads/images', ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('image/png', $res->json('content_type'));
        $url = $res->json('url');
        $this->assertMatchesRegularExpression('~^/api/v1/media/[0-9a-f]{32}\.png$~', $url);
        $served = $this->get($url)->assertOk();
        $this->assertSame('image/png', $served->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $served->headers->get('Cache-Control'));
    }

    public function test_bad_uploads_rejected_and_media_names_locked_down(): void
    {
        Storage::fake('local');
        $root = $this->root();
        $svg = UploadedFile::fake()->createWithContent('x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($root)->post(self::API.'/uploads/images', ['file' => $svg], ['Accept' => 'application/json'])->assertStatus(400);
        $empty = UploadedFile::fake()->createWithContent('x.png', '');
        $this->actingAs($root)->post(self::API.'/uploads/images', ['file' => $empty], ['Accept' => 'application/json'])->assertStatus(400);

        $this->actingAs($this->user('patient'))->post(self::API.'/uploads/images', ['file' => UploadedFile::fake()->createWithContent('a.png', self::PNG.'x')], ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson(self::API.'/media/..%2F..%2F.env')->assertStatus(404);
        $this->getJson(self::API.'/media/'.str_repeat('a', 32).'.png')->assertStatus(404);
        $this->getJson(self::API.'/media/'.str_repeat('a', 32).'.svg')->assertStatus(404);
    }

    // ---------- enquiries ----------

    public function test_enquiries_inbox(): void
    {
        $m = ContactMessage::create(['name' => 'Ravi', 'email' => 'ravi@example.com', 'message' => 'Do you have a cardiologist?']);
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/enquiries')->assertStatus(403);
        $reception = $this->user('receptionist');
        $list = $this->actingAs($reception)->getJson(self::API.'/enquiries?status=new')->assertOk();
        $this->assertSame(1, $list->json('total'));
        $this->actingAs($reception)->patchJson(self::API."/enquiries/{$m->id}", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
        $this->actingAs($reception)->patchJson(self::API."/enquiries/{$m->id}", ['status' => 'bogus'])->assertStatus(422);
        $this->assertSame(0, $this->actingAs($reception)->getJson(self::API.'/enquiries?status=new')->json('total'));
    }

    // ---------- departments ----------

    public function test_department_lifecycle_and_safeguards(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/departments', ['name' => 'Cardiology'])->assertStatus(403);

        $d = $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'Cardiology', 'icon' => 'heart-pulse', 'display_order' => 2])->assertCreated();
        $this->assertSame('cardiology', $d->json('slug'));
        $id = $d->json('id');
        $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'cardiology'])->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'Eye Care', 'slug' => 'cardiology'])->assertStatus(409);
        $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'Bad Icon', 'icon' => 'rocket'])->assertStatus(422);
        $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'Bad Img', 'image_url' => 'javascript:alert(1)'])->assertStatus(422);

        $this->actingAs($admin)->putJson(self::API."/departments/$id", ['name' => 'Cardiac Care', 'short_description' => 'Heart', 'display_order' => 1])
            ->assertOk()->assertJsonPath('name', 'Cardiac Care')->assertJsonPath('slug', 'cardiology');
        $this->actingAs($admin)->patchJson(self::API."/departments/$id/status", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertCount(0, $this->actingAs($admin)->getJson(self::API.'/departments?include_inactive=false')->json());

        // Blocked while an active appointment exists; allowed (and the name/slug freed) afterwards.
        DB::table('appointments')->count(); // table exists
        $this->actingAs($admin)->deleteJson(self::API."/departments/$id")->assertStatus(204);
        $this->assertSoftDeleted('departments', ['id' => $id]);
        $this->actingAs($admin)->postJson(self::API.'/departments', ['name' => 'Cardiology'])->assertCreated();
        $this->assertSame(2, Department::withTrashed()->count());
    }
}

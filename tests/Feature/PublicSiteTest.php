<?php

namespace Tests\Feature;

use App\Support\Hospital;
use Illuminate\Support\Facades\DB;
use Tests\HmsTestCase;

class PublicSiteTest extends HmsTestCase
{
    public function test_every_public_page_renders_with_seo_basics(): void
    {
        $dept = $this->department('Cardiology');
        $doc = $this->doctor([$dept['id']], ['full_name' => 'Dr Mira Nair', 'bio' => "First para.\n\nSecond para."]);
        foreach (['/', '/about', '/services', '/facilities', '/departments', '/departments/cardiology', '/doctors', "/doctors/{$doc['id']}", '/book', '/contact'] as $path) {
            $res = $this->get($path)->assertOk();
            $html = $res->getContent();
            $this->assertStringContainsString('<title>', $html, $path);
            $this->assertStringContainsString('rel="canonical"', $html, $path);
            $this->assertStringContainsString('name="description"', $html, $path);
            $this->assertSame(1, substr_count($html, '<h1'), "$path should have exactly one h1");
        }
    }

    public function test_home_shows_departments_doctors_and_structured_data(): void
    {
        $dept = $this->department('Neurology');
        $this->doctor([$dept['id']], ['full_name' => 'Dr Arun Rao', 'is_featured' => true]);
        $this->get('/')->assertOk()->assertSee('Neurology')->assertSee('Dr Arun Rao')->assertSee('"@type":"Hospital"', false)->assertSee('Book an appointment');
    }

    public function test_doctor_profile_has_physician_markup_and_slots(): void
    {
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']], ['full_name' => 'Dr Slot Test']);
        $date = Hospital::now()->addDays(2)->toDateString();
        DB::table('appointment_slots')->insert(['doctor_id' => $doc['id'], 'department_id' => $dept['id'], 'slot_date' => $date, 'start_time' => '10:30:00', 'end_time' => '10:45:00',
            'capacity' => 1, 'consultation_fee' => 600, 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->get("/doctors/{$doc['id']}")->assertOk()->assertSee('"@type":"Physician"', false)->assertSee('10:30 AM')->assertSee('Dr Slot Test');
        $this->get("/doctors/{$doc['id']}?date=".Hospital::now()->addDays(5)->toDateString())->assertOk()->assertSee('No open slots');
        $this->get('/doctors/99999')->assertNotFound();
    }

    public function test_inactive_and_missing_pages_are_not_found_and_content_is_escaped(): void
    {
        $dept = $this->department('<script>alert(1)</script>');
        $this->get('/departments/nope')->assertNotFound();
        $this->get('/departments')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($this->root())->patchJson(self::API."/departments/{$dept['id']}/status", ['is_active' => false]);
        $this->get('/departments/'.$dept['slug'])->assertNotFound();
    }

    public function test_doctor_filters_are_noindexed_and_do_not_leak_contact_details(): void
    {
        $dept = $this->department();
        $doc = $this->doctor([$dept['id']]);
        $this->get('/doctors')->assertOk()->assertDontSee('noindex', false);
        $filtered = $this->get('/doctors?search=Test')->assertOk()->assertSee('noindex', false);
        $this->assertStringNotContainsString($doc['email'], $filtered->getContent());
        $this->assertStringNotContainsString($doc['mobile'], $this->get("/doctors/{$doc['id']}")->getContent());
    }

    public function test_contact_form_stores_message_and_honeypot_discards_bots(): void
    {
        $body = ['name' => 'Ravi Kumar', 'email' => 'ravi@example.com', 'message' => 'Do you have a cardiologist on Saturdays?'];
        $this->post('/contact', $body)->assertRedirect(url('/contact').'#form')->assertSessionHas('sent');
        $this->assertDatabaseHas('contact_messages', ['email' => 'ravi@example.com']);
        $this->post('/contact', $body + ['website' => 'spam'])->assertRedirect();
        $this->assertDatabaseCount('contact_messages', 1);
        $this->post('/contact', ['name' => 'X', 'email' => 'bad', 'message' => 'short'])->assertSessionHasErrors(['name', 'email', 'message']);
    }

    public function test_sitemap_and_robots(): void
    {
        $dept = $this->department('Cardiology');
        $doc = $this->doctor([$dept['id']]);
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/departments/cardiology', $xml);
        $this->assertStringContainsString("/doctors/{$doc['id']}", $xml);
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /api/', $robots);
        $this->assertStringContainsString('Sitemap:', $robots);
    }
}

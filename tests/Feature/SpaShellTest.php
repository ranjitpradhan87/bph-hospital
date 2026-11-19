<?php

namespace Tests\Feature;

use Tests\HmsTestCase;

class SpaShellTest extends HmsTestCase
{
    public function test_app_routes_serve_the_react_shell_and_are_not_indexed(): void
    {
        foreach (['/login', '/register', '/admin', '/admin/doctors/5', '/admin/settings', '/portal', '/portal/profile'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<div id="root"', $html, $path);
            $this->assertStringContainsString('noindex, nofollow', $html, $path);
            $this->assertStringContainsString('window.__BPH__', $html, $path);
        }
    }

    public function test_hospital_name_in_shell_is_escaped(): void
    {
        $this->actingAs($this->root())->putJson(self::API.'/settings', ['values' => ['hospital.name' => 'Care </script><b>X']])->assertOk();
        $html = $this->get('/login')->getContent();
        $this->assertStringNotContainsString('</script><b>X', $html);
    }
}

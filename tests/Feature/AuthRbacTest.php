<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\RbacGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthRbacTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SUPERADMIN_EMAIL' => 'root@example.com', 'SUPERADMIN_MOBILE' => '9000000001', 'SUPERADMIN_PASSWORD' => 'RootPass123'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        $this->seed(DatabaseSeeder::class);
    }

    private function root(): User
    {
        return User::where('email', 'root@example.com')->firstOrFail();
    }

    private function patient(array $o = []): array
    {
        $n = ++self::$n;

        return $o + [
            'full_name' => "Asha Patient $n", 'email' => "asha$n@example.com", 'mobile' => sprintf('98%08d', $n),
            'date_of_birth' => '1992-04-12', 'gender' => 'female', 'password' => 'Secret123', 'consent' => true,
        ];
    }

    private function user(string $role, array $o = []): User
    {
        $n = ++self::$n;
        $u = User::create($o + ['full_name' => "$role $n", 'email' => "$role$n@example.com", 'mobile' => sprintf('97%08d', $n), 'password' => 'Secret123']);
        $u->refresh()->roles()->sync(Role::where('code', $role)->pluck('id'));

        return $u;
    }

    private function newUserBody(array $roles): array
    {
        $n = ++self::$n;

        return ['full_name' => 'New Staff', 'email' => "new$n@example.com", 'mobile' => sprintf('96%08d', $n), 'password' => 'Secret123', 'roles' => $roles];
    }

    public function test_seed_creates_roles_permissions_and_super_admin(): void
    {
        $this->assertSame(9, Role::where('is_system', true)->count());
        $this->assertContains('super_admin', $this->root()->roleCodes());
        $this->assertTrue($this->root()->can('roles.manage'));
    }

    public function test_patient_registers_gets_code_and_is_signed_in(): void
    {
        $res = $this->postJson(self::API.'/auth/register', $this->patient())->assertCreated();
        $res->assertJsonPath('user.roles', ['patient']);
        $this->assertMatchesRegularExpression('/^PAT-\d{6}$/', $res->json('user.patient_code'));
        $this->getJson(self::API.'/auth/me')->assertOk();
    }

    public function test_register_rejects_duplicates_weak_passwords_and_missing_consent(): void
    {
        $p = $this->patient();
        $this->postJson(self::API.'/auth/register', $p)->assertCreated();
        $this->postJson(self::API.'/auth/register', $this->patient(['email' => $p['email']]))->assertStatus(409);
        $this->postJson(self::API.'/auth/register', $this->patient(['mobile' => $p['mobile']]))->assertStatus(409);
        $this->postJson(self::API.'/auth/register', $this->patient(['password' => 'short1']))->assertStatus(422);
        $this->postJson(self::API.'/auth/register', $this->patient(['password' => 'onlyletters']))->assertStatus(422);
        $bad = $this->postJson(self::API.'/auth/register', $this->patient(['consent' => false]))->assertStatus(422);
        $this->assertSame(['body', 'consent'], $bad->json('detail.0.loc'));
    }

    public function test_login_by_email_or_mobile_and_wrong_password(): void
    {
        $u = $this->user('receptionist');
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'Secret123'])->assertOk();
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->mobile, 'password' => 'Secret123'])->assertOk();
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'nope12345'])->assertStatus(401);
        $this->postJson(self::API.'/auth/login', ['identifier' => 'ghost@example.com', 'password' => 'nope12345'])->assertStatus(401);
    }

    public function test_account_locks_after_five_failures(): void
    {
        $u = $this->user('nurse');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'wrong12345']);
        }
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'Secret123'])->assertStatus(423);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $u = $this->user('nurse', ['is_active' => false]);
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'Secret123'])->assertStatus(403);
    }

    public function test_unauthenticated_requests_get_401_detail(): void
    {
        $this->getJson(self::API.'/auth/me')->assertStatus(401)->assertJson(['detail' => 'Not authenticated']);
        $this->getJson(self::API.'/users')->assertStatus(401);
    }

    public function test_logout_succeeds_for_signed_in_user(): void
    {
        $this->actingAs($this->user('nurse'))->postJson(self::API.'/auth/logout')->assertSuccessful();
    }

    public function test_change_password_requires_current_password(): void
    {
        $u = $this->user('nurse');
        $this->actingAs($u)->postJson(self::API.'/auth/change-password', ['current_password' => 'bad', 'new_password' => 'NewPass123'])->assertStatus(401);
        $this->actingAs($u)->postJson(self::API.'/auth/change-password', ['current_password' => 'Secret123', 'new_password' => 'NewPass123'])->assertSuccessful();
        $this->postJson(self::API.'/auth/login', ['identifier' => $u->email, 'password' => 'NewPass123'])->assertOk();
    }

    public function test_permission_middleware_blocks_users_without_permission(): void
    {
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/users')->assertStatus(403);
        $this->actingAs($this->user('nurse'))->getJson(self::API.'/permissions')->assertStatus(403);
        $this->actingAs($this->user('admin'))->getJson(self::API.'/users')->assertOk();
    }

    public function test_admin_cannot_grant_admin_roles_but_super_admin_can(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson(self::API.'/users', $this->newUserBody(['admin']))->assertStatus(403);
        $this->actingAs($admin)->postJson(self::API.'/users', $this->newUserBody(['receptionist']))->assertCreated();
        $this->actingAs($this->root())->postJson(self::API.'/users', $this->newUserBody(['admin']))->assertCreated();
        $this->actingAs($this->root())->postJson(self::API.'/users', $this->newUserBody(['patient']))->assertStatus(400);
    }

    public function test_cannot_change_own_status_and_last_super_admin_is_protected(): void
    {
        $root = $this->root();
        $this->actingAs($root)->patchJson(self::API."/users/{$root->id}/status", ['is_active' => false])->assertStatus(403);

        $second = $this->user('super_admin');
        $this->actingAs($second)->patchJson(self::API."/users/{$root->id}/status", ['is_active' => false])->assertOk();
        $this->assertSame(1, RbacGuard::activeSuperAdmins());

        $this->actingAs($this->user('admin'))->patchJson(self::API."/users/{$second->id}/status", ['is_active' => false])->assertStatus(403);
    }

    public function test_deactivating_user_deletes_their_sessions(): void
    {
        $nurse = $this->user('nurse');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $nurse->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->user('admin'))->patchJson(self::API."/users/{$nurse->id}/status", ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $nurse->id]);
    }

    public function test_custom_roles_cannot_escalate_and_system_roles_are_immutable(): void
    {
        $root = $this->root();
        $create = $this->actingAs($root)->postJson(self::API.'/roles', ['code' => 'billing_lead', 'name' => 'Billing lead', 'permissions' => ['billing.read', 'roles.manage']])->assertCreated();
        $this->assertContains('roles.manage', $create->json('permissions'));
        $this->actingAs($root)->postJson(self::API.'/roles', ['code' => 'bogus', 'name' => 'Bogus', 'permissions' => ['nope.nope']])->assertStatus(400);
        $this->actingAs($root)->postJson(self::API.'/roles', ['code' => 'billing_lead', 'name' => 'Dup'])->assertStatus(409);

        $adminRole = Role::where('code', 'admin')->first();
        $this->actingAs($root)->putJson(self::API."/roles/{$adminRole->id}/permissions", ['permissions' => []])->assertStatus(400);
        $this->actingAs($root)->deleteJson(self::API."/roles/{$adminRole->id}")->assertStatus(400);

        // An admin cannot hand out a custom role carrying permissions they lack.
        $this->actingAs($this->user('admin'))->postJson(self::API.'/users', $this->newUserBody(['billing_lead']))->assertStatus(403);
    }
}

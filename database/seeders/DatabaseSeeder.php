<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\SettingsRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Idempotent reference data: permissions, system roles and the first super admin. Safe to re-run. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [];
        foreach (Permissions::ALL as $code => $description) {
            $perms[$code] = Permission::updateOrCreate(
                ['code' => $code],
                ['module' => explode('.', $code)[0], 'description' => $description],
            );
        }

        foreach (Permissions::roles() as $code => $spec) {
            $role = Role::updateOrCreate(
                ['code' => $code],
                ['name' => $spec['name'], 'description' => $spec['description'], 'is_system' => true],
            );
            // System roles always mirror the catalogue; custom roles are managed through the API.
            $role->permissions()->sync(array_map(fn ($c) => $perms[$c]->id, $spec['permissions']));
        }

        $this->settings();
        $this->ipdHeads();
        $this->labTests();
        $this->superAdmin();
    }

    /** A starter list of common tests. Prices are placeholders: the lab manager sets the real ones in Lab > Test catalogue. */
    private function labTests(): void
    {
        $tests = [
            ['Hematology', 'Blood', 'Complete blood count (CBC)', 300, 4, null, null], ['Hematology', 'Blood', 'Haemoglobin', 100, 2, 'g/dL', '12 - 16'],
            ['Hematology', 'Blood', 'ESR', 100, 2, 'mm/hr', '0 - 20'], ['Hematology', 'Blood', 'Blood group & Rh', 100, 2, null, null],
            ['Biochemistry', 'Blood', 'Blood sugar - fasting', 80, 2, 'mg/dL', '70 - 100'], ['Biochemistry', 'Blood', 'Blood sugar - post meal', 80, 2, 'mg/dL', '< 140'],
            ['Biochemistry', 'Blood', 'HbA1c', 500, 6, '%', '4.0 - 5.6'], ['Biochemistry', 'Blood', 'Lipid profile', 600, 6, null, null],
            ['Biochemistry', 'Blood', 'Liver function test (LFT)', 700, 6, null, null], ['Biochemistry', 'Blood', 'Kidney function test (KFT)', 650, 6, null, null],
            ['Biochemistry', 'Blood', 'Serum creatinine', 200, 4, 'mg/dL', '0.6 - 1.3'], ['Biochemistry', 'Blood', 'Blood urea', 150, 4, 'mg/dL', '15 - 40'],
            ['Biochemistry', 'Blood', 'Uric acid', 200, 4, 'mg/dL', '3.5 - 7.2'], ['Biochemistry', 'Blood', 'Serum calcium', 200, 4, 'mg/dL', '8.5 - 10.5'],
            ['Hormones', 'Blood', 'Thyroid stimulating hormone (TSH)', 350, 8, 'uIU/mL', '0.4 - 4.0'], ['Hormones', 'Blood', 'Thyroid profile (T3, T4, TSH)', 700, 8, null, null],
            ['Serology', 'Blood', 'Widal test', 200, 4, null, null], ['Serology', 'Blood', 'Dengue NS1 antigen', 700, 4, null, null],
            ['Serology', 'Blood', 'Malaria parasite', 250, 3, null, null], ['Serology', 'Blood', 'HBsAg', 250, 4, null, null], ['Serology', 'Blood', 'HIV I & II', 300, 4, null, null],
            ['Clinical pathology', 'Urine', 'Urine routine & microscopy', 120, 3, null, null], ['Clinical pathology', 'Stool', 'Stool routine', 120, 3, null, null],
            ['Radiology', null, 'X-ray chest', 400, 1, null, null], ['Radiology', null, 'Ultrasound (USG) abdomen', 800, 2, null, null], ['Cardiology', null, 'ECG', 250, 1, null, null],
        ];
        foreach ($tests as $i => [$category, $sample, $name, $price, $hours, $unit, $range]) {
            if (DB::table('lab_tests')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                continue;
            }
            $id = DB::table('lab_tests')->insertGetId(['code' => 'TMP-'.bin2hex(random_bytes(6)), 'name' => $name, 'category' => $category, 'sample_type' => $sample, 'price' => $price, 'turnaround_hours' => $hours,
                'unit' => $unit, 'reference_range' => $range, 'is_active' => true, 'display_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lab_tests')->where('id', $id)->update(['code' => sprintf('LT-%04d', $id)]);
        }
    }

    /** The columns of the old IPD spreadsheet; existing columns (and any renames or retyping by an admin) are left alone. */
    private function ipdHeads(): void
    {
        foreach (\App\Http\Controllers\Api\IpdController::DEFAULT_HEADS as $i => [$code, $name, $type]) {
            if (! DB::table('ipd_heads')->where('code', $code)->exists()) {
                DB::table('ipd_heads')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'display_order' => $i + 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** Insert any missing setting with its default; never overwrites values an admin has edited. */
    private function settings(): void
    {
        foreach (SettingsRegistry::all() as $spec) {
            if (DB::table('hospital_settings')->where('key', $spec['key'])->exists()) {
                continue;
            }
            DB::table('hospital_settings')->insert([
                'key' => $spec['key'], 'group' => $spec['group'], 'is_public' => $spec['public'],
                'value' => json_encode($spec['default'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function superAdmin(): void
    {
        $role = Role::where('code', 'super_admin')->first();
        if ($role->users()->exists()) {
            return;
        }
        $password = env('SUPERADMIN_PASSWORD') ?: Str::password(14, symbols: false);
        $user = User::create([
            'email' => strtolower(env('SUPERADMIN_EMAIL', 'admin@example.com')),
            'mobile' => env('SUPERADMIN_MOBILE', '9000000000'),
            'full_name' => 'Super Administrator',
            'password' => $password,
        ]);
        $user->roles()->attach($role->id);

        if (! env('SUPERADMIN_PASSWORD')) {
            $this->command?->warn("Super admin created: {$user->email}");
            $this->command?->warn("Generated password (shown once, change it after first login): {$password}");
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class ResetSuperAdmin extends Command
{
    protected $signature = 'admin:reset {--email= : New login email} {--password= : New password} {--mobile= : Mobile number}';

    protected $description = 'Create the super admin, or reset its email and password (falls back to SUPERADMIN_* env vars)';

    public function handle(): int
    {
        $email = strtolower((string) ($this->option('email') ?: env('SUPERADMIN_EMAIL')));
        $password = (string) ($this->option('password') ?: env('SUPERADMIN_PASSWORD'));
        $mobile = (string) ($this->option('mobile') ?: env('SUPERADMIN_MOBILE', '9000000000'));
        if ($email === '' || strlen($password) < 8) {
            $this->error('Set SUPERADMIN_EMAIL and a SUPERADMIN_PASSWORD of at least 8 characters.');

            return self::FAILURE;
        }
        $role = Role::where('code', 'super_admin')->first();
        if (! $role) {
            $this->error('Roles are missing. Run: php artisan db:seed --force');

            return self::FAILURE;
        }
        $user = $role->users()->first() ?? new User(['full_name' => 'Super Administrator']);
        $user->fill(['email' => $email, 'mobile' => $mobile, 'password' => $password])->save();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->info("Super admin ready: {$email}");

        return self::SUCCESS;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $production = app()->isProduction();

        $superEmail = config('topup.seed.super_admin_email') ?: ($production ? null : 'superadmin@topup.test');

        if (! $superEmail) {
            $this->command?->error('Set SEED_SUPER_ADMIN_EMAIL in .env before seeding in production.');

            return;
        }

        $password = config('topup.seed.password');
        $generated = false;
        if (blank($password)) {
            $password = Str::password(16);
            $generated = true;
        }

        $accounts = [[$superEmail, 'Super Admin', UserRole::SuperAdmin]];

        if (! $production) {
            $accounts[] = [config('topup.seed.admin_email') ?: 'admin@topup.test', 'Admin', UserRole::Admin];
            $accounts[] = [config('topup.seed.support_email') ?: 'support@topup.test', 'Support', UserRole::Support];
            $accounts[] = ['user@topup.test', 'Demo User', UserRole::User];
        }

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::firstOrNew(['email' => $email]);

            if ($user->exists) {
                $this->command?->line("Skipped (exists): {$email}");

                continue;
            }

            $user->forceFill([
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $this->command?->info("Created {$role->label()}: {$email}");
        }

        if ($generated) {
            $this->command?->warn("Generated password for NEW accounts (shown once): {$password}");
        }
    }
}
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the first admin from the environment. The password is only used
     * when the account does not exist yet, so re-seeding never resets a
     * password the operator has since changed.
     */
    public function run(): void
    {
        $email = config('app.admin.email');
        $password = config('app.admin.password');
        $name = config('app.admin.name') ?: 'Store Admin';

        if (blank($email) || blank($password)) {
            if (app()->environment('production')) {
                throw new RuntimeException('ADMIN_EMAIL and ADMIN_PASSWORD must be set before seeding production.');
            }

            $this->command?->warn('ADMIN_EMAIL and ADMIN_PASSWORD are not set; skipping the admin seed.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        if (! $user->wasRecentlyCreated && $user->role !== User::ROLE_ADMIN) {
            $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        }
    }
}

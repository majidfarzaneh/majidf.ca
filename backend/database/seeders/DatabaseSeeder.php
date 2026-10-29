<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The super admin. Sign in with this email at /portal/login.
        User::query()->updateOrCreate(
            ['email' => mb_strtolower(config('business.admin_email'))],
            ['name' => config('business.admin_name'), 'role' => Role::Admin, 'is_active' => true],
        );

        if (app()->environment('local') || env('SEED_DEMO')) {
            $this->call(DemoSeeder::class);
        }
    }
}

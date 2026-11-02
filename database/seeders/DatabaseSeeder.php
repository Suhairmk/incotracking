<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                'No admin seeded. Set ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD, or run php artisan app:create-admin.'
            );

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'employee_code' => env('ADMIN_EMPLOYEE_CODE', 'ADMIN001'),
                'name' => env('ADMIN_NAME', 'Administrator'),
                'phone' => env('ADMIN_PHONE') ?: null,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->command?->info("Admin account ready for {$email}.");
    }
}

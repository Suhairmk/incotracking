<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Full name}
        {--email= : Login email}
        {--phone= : Optional phone}
        {--password= : Password (omit to be prompted)}
        {--code=ADMIN001 : Employee code}';

    protected $description = 'Create or update an administrator account without seeding fake data';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Admin name');
        $email = strtolower($this->option('email') ?: $this->ask('Admin email'));
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');
        $phone = $this->option('phone');
        $code = strtoupper($this->option('code') ?: 'ADMIN001');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }

        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'employee_code' => $code,
                'name' => $name,
                'phone' => $phone ?: null,
                'password' => Hash::make((string) $password),
                'role' => UserRole::Admin,
                'is_active' => true,
            ]
        );

        $this->info("Administrator {$admin->email} is ready.");
        $this->line('Employee code: '.$admin->employee_code);

        return self::SUCCESS;
    }
}

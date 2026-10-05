<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development only. In production, create users with
     * `php artisan make:filament-user`.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipping: create users with php artisan make:filament-user.');

            return;
        }

        User::firstOrCreate(
            ['email' => 'admin@modbot.test'],
            ['name' => 'Local admin', 'password' => 'password'],
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        $adminEmail = 'admin@crockenhill.org';

        // Ensure the admin user exists and stays up to date without duplicate key errors.
        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        // Keep a stable baseline of seeded non-admin users.
        $targetRegularUsers = 9;
        $existingRegularUsers = User::where('email', '!=', $adminEmail)->count();

        if ($existingRegularUsers < $targetRegularUsers) {
            User::factory($targetRegularUsers - $existingRegularUsers)->create();
        }
    }
}

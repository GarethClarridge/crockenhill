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
        // Using firstOrNew and explicit assignment to bypass mass-assignment protection on is_admin.
        $admin = User::firstOrNew(['email' => $adminEmail]);
        $admin->name = 'Admin User';
        $admin->password = Hash::make('password');
        $admin->is_admin = true;
        $admin->email_verified_at = now();
        $admin->save();

        // Keep a stable baseline of seeded non-admin users.
        $targetRegularUsers = 9;
        $existingRegularUsers = User::where('email', '!=', $adminEmail)->count();

        if ($existingRegularUsers < $targetRegularUsers) {
            User::factory($targetRegularUsers - $existingRegularUsers)->create();
        }
    }
}

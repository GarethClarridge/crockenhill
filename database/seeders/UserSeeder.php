<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin1@crockenhill.org',
            'password' => Hash::make('password'),
        ]);

        // Create Mark Drury (main preacher)
        User::create([
            'name' => 'Mark Drury',
            'email' => 'markdrury@crockenhill.org',
            'password' => Hash::make('password'),
        ]);

        // Create additional users
        User::factory(8)->create();
    }
}

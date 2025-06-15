<?php

namespace Database\Seeders;

use Crockenhill\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
  public function run()
  {
    // Create admin user
    User::create([
      'name' => 'Admin User',
      'email' => 'admin@crockenhill.org',
      'password' => Hash::make('password'),
    ]);

    // Create Mark Drury (main preacher)
    User::create([
      'name' => 'Mark Drury',
      'email' => 'mark@crockenhill.org',
      'password' => Hash::make('password'),
    ]);

    // Create additional users
    User::factory(8)->create();
  }
}

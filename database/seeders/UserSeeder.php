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
      'is_admin' => true,
      'is_member' => true,
    ]);

    // Create Mark Drury (main preacher)
    User::create([
      'name' => 'Mark Drury',
      'email' => 'markdrury@crockenhill.org',
      'password' => Hash::make('password'),
      'is_member' => true,
    ]);

    // Create user from old hardcoded list
    User::create([
      'name' => 'Gareth Clarridge',
      'email' => 'garethclarridge@hotmail.co.uk',
      'password' => Hash::make('password'), // Default password, user should reset
      'is_member' => true,
    ]);

    // Create additional users
    User::factory(8)->create();
  }
}

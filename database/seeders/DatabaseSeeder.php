<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class DatabaseSeeder extends Seeder {

	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
		Model::unguard();

		//Sermon seeding

    $this->call('SermonSeeder');
      $this->command->info('Sermons table seeded!');

    // Users seeding

    DB::table('users')->delete();
    DB::table('password_reminders')->delete();
    DB::table('permission_role')->delete();
    DB::table('assigned_roles')->delete();
    DB::table('roles')->delete();
    DB::table('permissions')->delete();

		$this->call('PermissionSeeder');
      $this->command->info('Permissions table seeded!');

		$this->call('RoleSeeder');
      $this->command->info('Roles tables seeded!');

		$this->call('UserSeeder');
      $this->command->info('Users tables seeded!');

    // Pages seeding

      // !!Do not use, as there is no way of seeding the pages table!!

    //$this->call('PageSeeder');
      //$this->command->info('Pages table seeded!');

    // Document seeding
    $this->call('DocumentSeeder');
      $this->command->info('Documents table seeded!');
	}

}

<?php

class DatabaseSeeder extends Seeder {

	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
		Eloquent::unguard();

		//$this->call('SermonSeeder');
      //$this->command->info('Sermons table seeded!');

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
	}

}

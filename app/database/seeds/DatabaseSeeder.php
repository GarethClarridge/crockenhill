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

		$this->call('PageSeeder');
		$this->command->info('Pages table seeded!');
		
		$this->call('UserSeeder');
		$this->command->info('Users table seeded!');
		
		$this->call('RoleSeeder');
		$this->command->info('Roles and associated pivot table seeded!');
		
		$this->call('SermonSeeder');
        $this->command->info('Sermons table seeded!');
	}

}

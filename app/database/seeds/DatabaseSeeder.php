<?php

class DatabaseSeeder extends Seeder {

	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
        $this->call('SentrySeeder');
        $this->command->info('Sentry tables seeded!');
 
        $this->call('ContentSeeder');
        $this->command->info('Content tables seeded!');
	}

}

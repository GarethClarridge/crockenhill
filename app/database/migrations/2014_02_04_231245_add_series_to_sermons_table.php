<?php

use Illuminate\Database\Migrations\Migration;

class AddSeriesToSermonsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table('sermons', function($table)
		{
			$table->string('series');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::table('sermons', function($table)
		{
			$table->dropColumn('series');
		});
	}

}

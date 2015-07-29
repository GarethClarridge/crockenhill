<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePagesTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
    // Don't want to use migrations for pages table,
    // as we don't have a way to seed it yet.

    /*Schema::create('sermons', function ($table)
      {
    		$table->increments('id');
        $table->string('slug');
        $table->string('heading');
        $table->text('description');
        $table->string('area', 50);
        $table->text('body');
        $table->timestamps();
      });*/
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
    // Don't want to use migrations for pages table,
    // as we don't have a way to seed it yet.

		//Schema::drop('pages');
	}

}

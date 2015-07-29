<?php

use Illuminate\Database\Migrations\Migration;

class CreateSermonsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('sermons', function ($table)
      {
        $table->increments('id');
        $table->date('date');
        $table->enum('service', array('morning', 'evening'))->nullable();
        $table->string('filename', 9);
        $table->string('filetype', 8)->default('mp3');
        $table->string('title', 75);
        $table->string('slug', 50);
        $table->string('reference', 50)->nullable();
        $table->string('preacher', 30)->nullable()->default('Mark Drury');
        $table->string('series', 25)->nullable();
        $table->timestamps();
      });
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
    Schema::drop('sermons');
	}

}

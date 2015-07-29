<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class OtherSongsTables extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('play_date', function ($table)
      {
        $table->increments('id');
        $table->smallInteger('song_id');
        $table->date('date');
        $table->timestamps();
      });

		Schema::create('scripture_references', function ($table)
      {
        $table->increments('id');
        $table->string('reference', 11);
        $table->smallInteger('song_id');
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
    Schema::drop('play_date');
    Schema::drop('scripture_references');
	}

}

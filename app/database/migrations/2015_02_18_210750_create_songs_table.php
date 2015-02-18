<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSongsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('songs', function ($table)
      {
        $table->increments('song_id');
        $table->string('praise_number', 5);
        $table->string('title', 100);
        $table->string('author', 100);
        $table->text('lyrics')->nullable();
        $table->string('copyright', 100)->nullable();
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
    Schema::drop('songs');
	}

}

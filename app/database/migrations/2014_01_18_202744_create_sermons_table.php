<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSermonsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
        Schema::create('sermons', function(Blueprint $table)
        {
            $table->increments('id');
            $table->string('title');
            $table->string('slug');
            $table->text('body')->nullable();
            $table->string('image')->nullable();
            $table->integer('user_id');
            $table->string('preacher');
            $table->date('date');
            $table->string('scripture');
            $table->string('filename');
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

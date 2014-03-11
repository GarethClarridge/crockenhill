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
		Schema::create('sermons', function($table)
        {
            $table->increments('id');
            $table->string('title');
            $table->string('slug');
            $table->string('preacher');
            $table->date('date');
            $table->string('service');
            $table->string('scripture');
            $table->text('body')->nullable();
            $table->string('image')->nullable();
            $table->integer('user_id')->nullable();
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

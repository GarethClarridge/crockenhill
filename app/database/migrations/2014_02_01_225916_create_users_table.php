<?php

use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
        Schema::create(
            'users',
            function ($table) {
                $table->increments('id');
                $table->string('email', 50);
                $table->string('password', 64);
                $table->string('username', 50);
                $table->string('first_name', 50)->nullable();
                $table->string('last_name', 50)->nullable();
                $table->timestamps();
            }
        );
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('users');
	}

}

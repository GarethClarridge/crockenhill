<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDocumentsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('documents', function ($table)
      {
        $table->increments('id');
        $table->string('title', 75);
        $table->string('type', 50);
        $table->string('filename', 9);
        $table->string('filetype', 8);
        $table->string('owner', 50);
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
    Schema::drop('documents');
	}

}

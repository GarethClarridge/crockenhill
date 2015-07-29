<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMeetingsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('meetings', function ($table)
      {
        $table->increments('id');
        $table->string('heading', 75);
        $table->string('slug', 75);
        $table->enum('type', array('SundayAndBibleStudies',
            'ChildrenAndYoungPeople',
            'Adults',
            'Occasional'));
        $table->string('description', 160);
        $table->text('body');
        $table->time('StartTime');
        $table->time('EndTime')->nullable();
        $table->string('day', 75);
        $table->string('location', 75);
        $table->string('who', 75);
        $table->boolean('pictures');
        $table->string('LeadersPhone', 10)->nullable();
        $table->string('LeadersEmail', 50)->nullable();
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
    Schema::drop('meetings');
	}

}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeetingsTable extends Migration
{
  public function up()
  {
    Schema::create('meetings', function (Blueprint $table) {
      $table->increments('id');
      $table->string('name'); // Added name field
      $table->string('slug', 75);
      $table->enum('type', ['SundayAndBibleStudies', 'ChildrenAndYoungPeople', 'Adults', 'Occasional']);
      $table->dateTime('meeting_date')->nullable(); // Added meeting_date
      $table->time('StartTime')->nullable();
      $table->time('EndTime')->nullable();
      $table->string('day', 75);
      $table->string('location', 75);
      $table->string('who', 75);
      $table->boolean('pictures');
      $table->boolean('is_recurring')->default(false); // Added is_recurring
      $table->string('frequency')->nullable(); // Added frequency
      $table->string('LeadersPhone', 10)->nullable();
      $table->string('LeadersEmail', 50)->nullable();
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('meetings');
  }
}

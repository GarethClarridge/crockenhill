<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeetingsTable extends Migration
{
  public function up()
  {
    if (!Schema::hasTable('meetings')) {
      Schema::create('meetings', function (Blueprint $table) {
        $table->increments('id');
        $table->string('slug', 75);
        $table->enum('type', ['SundayAndBibleStudies', 'ChildrenAndYoungPeople', 'Adults', 'Occasional']);
      $table->time('StartTime')->nullable();
      $table->time('EndTime')->nullable();
      $table->string('day', 75);
      $table->string('location', 75)->nullable(); // Made location nullable
      $table->string('who', 75);
      $table->boolean('pictures');
      $table->string('LeadersPhone', 10)->nullable();
      $table->string('LeadersEmail', 50)->nullable();
      $table->timestamps();
    });
  } // Closing brace for the if statement
}

  public function down()
  {
    Schema::dropIfExists('meetings');
  }
}

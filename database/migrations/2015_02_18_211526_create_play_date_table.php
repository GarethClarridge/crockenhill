<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlayDateTable extends Migration
{
  public function up()
  {
    Schema::create('play_date', function (Blueprint $table) {
      $table->increments('id');
      $table->unsignedInteger('song_id'); // Explicitly set type
      $table->unsignedInteger('sermon_id')->nullable(); // Explicitly set type for sermon_id
      $table->date('date');

      $table->foreign('song_id')->references('id')->on('songs')->onDelete('cascade'); // Define foreign key
      $table->foreign('sermon_id')->references('id')->on('sermons')->onDelete('set null'); // Define foreign key for sermon_id
      $table->enum('time', ['a', 'p'])->nullable();
      $table->timestamp('played_on')->nullable(); // Added played_on column
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('play_date');
  }
}

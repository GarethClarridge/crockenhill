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
      $table->string('song_id');
      $table->date('date');
      $table->enum('time', ['a', 'p'])->nullable();
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('play_date');
  }
}

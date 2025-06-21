<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSongsTable extends Migration
{
  public function up()
  {
    Schema::create('songs', function (Blueprint $table) {
      $table->increments('id');
      $table->string('praise_number', 5)->nullable();
      $table->string('title', 100);
      $table->string('author', 100)->nullable();
      $table->text('lyrics')->nullable();
      $table->string('copyright', 100)->nullable();
      $table->string('alternative_title', 100)->nullable();
      $table->boolean('current')->default(true);
      $table->text('notes')->nullable();
      $table->string('major_category', 100)->nullable(); // Changed from enum
      $table->string('minor_category', 100)->nullable(); // Changed from enum
      $table->timestamp('last_played_at')->nullable(); // Added
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('songs');
  }
}

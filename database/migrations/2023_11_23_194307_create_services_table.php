<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServicesTable extends Migration
{
  public function up()
  {
    Schema::create('services', function (Blueprint $table) {
      $table->id();
      $table->date('date');
      $table->enum('type', ['morning', 'evening']);
      $table->string('video');
      $table->string('audio');
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('services');
  }
}

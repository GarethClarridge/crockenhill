<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePagesTable extends Migration
{
  public function up()
  {
    Schema::create('pages', function (Blueprint $table) {
      $table->increments('id');
      $table->string('slug');
      $table->string('heading');
      $table->text('description');
      $table->string('area', 50);
      $table->text('body');
      $table->enum('admin', ['yes', 'no'])->default('no');
      $table->text('markdown')->nullable();
      $table->boolean('navigation');
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('pages');
  }
}

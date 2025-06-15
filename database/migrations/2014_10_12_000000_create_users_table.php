<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
  public function up()
  {
    Schema::create('users', function (Blueprint $table) {
      $table->increments('id');
      $table->string('name');
      $table->string('email')->unique();
      $table->string('password', 60);
      $table->string('remember_token', 100)->nullable();
      $table->timestamp('created_at')->default('0000-00-00 00:00:00');
      $table->timestamp('updated_at')->default('0000-00-00 00:00:00');
    });
  }

  public function down()
  {
    Schema::dropIfExists('users');
  }
}

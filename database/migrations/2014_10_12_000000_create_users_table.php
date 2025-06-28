<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
  public function up()
  {
    if (!Schema::hasTable('users')) {
      Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
      $table->string('password', 60);
      // $table->boolean('is_admin')->default(false); // To be added in a separate migration
      $table->rememberToken();
      $table->timestamps();
    });
  } // Closing brace for the if statement
}

  public function down()
  {
    Schema::dropIfExists('users');
  }
}

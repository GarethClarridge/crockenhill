<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJobsTable extends Migration
{
  public function up()
  {
    if (!Schema::hasTable('jobs')) {
      Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
      $table->unsignedTinyInteger('attempts');
      $table->unsignedInteger('reserved_at')->nullable();
      $table->unsignedInteger('available_at');
      $table->unsignedInteger('created_at');
    });
  } // Closing brace for the if statement
}

  public function down()
  {
    Schema::dropIfExists('jobs');
  }
}

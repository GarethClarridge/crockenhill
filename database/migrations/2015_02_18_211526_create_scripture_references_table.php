<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScriptureReferencesTable extends Migration
{
  public function up()
  {
    Schema::create('scripture_references', function (Blueprint $table) {
      $table->increments('id');
      $table->string('reference', 11);
      $table->smallInteger('song_id');
      $table->timestamp('created_at')->default('0000-00-00 00:00:00');
      $table->timestamp('updated_at')->default('0000-00-00 00:00:00');
    });
  }

  public function down()
  {
    Schema::dropIfExists('scripture_references');
  }
}

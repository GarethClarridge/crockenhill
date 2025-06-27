<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSermonsTable extends Migration
{
  public function up()
  {
    Schema::create('sermons', function (Blueprint $table) {
      $table->increments('id');
      $table->foreignId('service_id')->nullable()->constrained('services')->onDelete('set null'); // Added service_id FK
      $table->date('date');
      $table->enum('service_type_enum', ['morning', 'evening'])->nullable(); // Renamed old 'service' column
      $table->string('filename', 75);
      $table->string('filetype', 8)->default('mp3');
      $table->string('title', 75);
      $table->string('slug', 75);
      $table->string('reference', 75)->nullable();
      $table->string('preacher', 75)->default('Mark Drury');
      $table->string('series', 75)->nullable();
      $table->text('points')->nullable();
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('sermons');
  }
}

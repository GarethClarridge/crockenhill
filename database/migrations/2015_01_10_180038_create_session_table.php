
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSessionTable extends Migration
{
  public function up()
  {
    if (!Schema::hasTable('sessions')) {
      Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->unique();
        $table->text('payload');
        $table->integer('last_activity');
      $table->integer('user_id')->nullable();
      $table->string('ip_address')->nullable();
      $table->text('user_agent');
    });
  }

  public function down()
  {
    Schema::dropIfExists('sessions');
  }
}

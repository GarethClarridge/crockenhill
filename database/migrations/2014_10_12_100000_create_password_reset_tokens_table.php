
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePasswordResetTokensTable extends Migration
{
  public function up()
  {
    if (!Schema::hasTable('password_reset_tokens')) {
      Schema::create('password_reset_tokens', function (Blueprint $table) {
        $table->string('email')->index();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
  } // Closing brace for the if statement
}

  public function down()
  {
    Schema::dropIfExists('password_reset_tokens');
  }
}

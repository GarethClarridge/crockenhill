<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSongs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      Schema::table('songs', function (Blueprint $table) {
          $table->boolean('recommended')->default(1);
      });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
      Schema::table('songs', function (Blueprint $table) {
          $table->dropColumn('recommended');
      });
    }
}

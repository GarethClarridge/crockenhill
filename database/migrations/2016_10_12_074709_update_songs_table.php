<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSongsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      Schema::table('songs', function (Blueprint $table) {
          $table->string('major_category', 100)->nullable();
          $table->string('minor_category', 100)->nullable();
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
          $table->dropColumn('major_category');
          $table->dropColumn('minor_category');
      });
    }
}

<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeRecommendedToCurrentSongsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      Schema::table('songs', function (Blueprint $table) {
          $table->dropColumn('recommended');
          $table->boolean('current')->default(1);
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
          $table->boolean('recommended')->default(1);
      });
    }
}

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropColumnsFromMeetingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      Schema::table('meetings', function (Blueprint $table) {
        $table->dropColumn('heading');
        $table->dropColumn('description');
        $table->dropColumn('body');
      });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
      Schema::table('meetings', function (Blueprint $table) {
        $table->string('heading')->nullable();
        $table->string('description')->nullable();
        $table->text('body')->nullable();
      });
    }
}

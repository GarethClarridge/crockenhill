<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::table('sermons', function (Blueprint $table) {
      $table->string('transcript_path')->nullable()->after('points');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::table('sermons', function (Blueprint $table) {
      $table->dropColumn('transcript_path');
    });
  }
};

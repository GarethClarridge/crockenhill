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
        Schema::table('meetings', function (Blueprint $table) {
            $table->renameColumn('StartTime', 'start_time');
            $table->renameColumn('EndTime', 'end_time');
            $table->renameColumn('LeadersPhone', 'leaders_phone');
            $table->renameColumn('LeadersEmail', 'leaders_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->renameColumn('start_time', 'StartTime');
            $table->renameColumn('end_time', 'EndTime');
            $table->renameColumn('leaders_phone', 'LeadersPhone');
            $table->renameColumn('leaders_email', 'LeadersEmail');
        });
    }
};

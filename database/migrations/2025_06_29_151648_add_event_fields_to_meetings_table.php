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
            $table->dateTime('meeting_date')->nullable()->after('EndTime');
            $table->boolean('is_recurring')->default(false)->after('meeting_date');
            $table->string('frequency')->nullable()->after('is_recurring'); // e.g., daily, weekly, monthly
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['meeting_date', 'is_recurring', 'frequency']);
        });
    }
};
// Note: YYYY_MM_DD_HHMMSS will be replaced by the actual timestamp by the tool/system.

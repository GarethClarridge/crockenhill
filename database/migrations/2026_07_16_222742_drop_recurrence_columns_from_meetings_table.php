<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE meetings DROP CHECK meetings_recurring_frequency_check');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_meeting_date_index');
            $table->dropIndex('meetings_is_recurring_index');
            $table->dropColumn(['meeting_date', 'is_recurring', 'frequency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('meeting_date')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'annually'])->nullable();
            $table->index('meeting_date');
            $table->index('is_recurring');
        });

        DB::statement(
            'ALTER TABLE meetings ADD CONSTRAINT meetings_recurring_frequency_check '.
            'CHECK (is_recurring = 0 OR frequency IS NOT NULL)'
        );
    }
};

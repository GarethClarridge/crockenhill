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
            // SQLite does not support changing column types directly in this manner.
            // We'll skip this change for SQLite.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                // Change the 'points' column to JSON type.
                // It's already nullable based on the schema, so we keep ->nullable().
                $table->json('points')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // SQLite does not support changing column types directly in this manner.
            // We'll skip this change for SQLite.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                // Revert the 'points' column back to TEXT type.
                $table->text('points')->nullable()->change();
            }
        });
    }
};

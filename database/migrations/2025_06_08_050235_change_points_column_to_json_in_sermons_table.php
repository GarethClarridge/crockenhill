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
            // It's good practice to ensure the column exists before trying to change it,
            // though in this specific flow we know it does.
            // Change the 'points' column to JSON type.
            // It's already nullable based on the schema, so we keep ->nullable().
            $table->json('points')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // Revert the 'points' column back to TEXT type.
            $table->text('points')->nullable()->change();
        });
    }
};

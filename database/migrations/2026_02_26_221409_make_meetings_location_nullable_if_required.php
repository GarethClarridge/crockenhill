<?php

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
        if (! Schema::hasTable('meetings') || ! Schema::hasColumn('meetings', 'location')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE meetings MODIFY location VARCHAR(75) NULL');

            return;
        }

        Schema::table('meetings', function (Blueprint $table): void {
            $table->string('location', 75)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive: this migration aligns drifted schemas.
    }
};

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
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->boolean('is_degraded_completion')->default(false)->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropColumn('is_degraded_completion');
        });
    }
};

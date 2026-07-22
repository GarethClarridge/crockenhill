<?php

declare(strict_types=1);

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
            $table->timestamp('superseded_at')->nullable()->after('current_step');
            $table->unsignedBigInteger('superseded_by_processing_log_id')->nullable()->after('superseded_at');
            $table->index('superseded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropIndex(['superseded_at']);
            $table->dropColumn(['superseded_at', 'superseded_by_processing_log_id']);
        });
    }
};

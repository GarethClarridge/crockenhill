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
        Schema::create('speaker_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaker_profile_id')->constrained('speaker_profiles')->cascadeOnDelete();
            // sermons.id is int (not bigint), so we use unsignedInteger with an explicit FK
            $table->unsignedInteger('sermon_id')->nullable();
            $table->foreign('sermon_id')->references('id')->on('sermons')->nullOnDelete();
            $table->foreignId('media_processing_log_id')->nullable()->constrained('media_processing_logs')->nullOnDelete();
            $table->json('embedding');
            $table->float('duration_seconds');
            $table->float('quality_score')->nullable();
            $table->string('source', 30);
            $table->boolean('approved')->default(false);
            $table->timestamps();

            $table->index('speaker_profile_id');
            $table->index('sermon_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speaker_samples');
    }
};

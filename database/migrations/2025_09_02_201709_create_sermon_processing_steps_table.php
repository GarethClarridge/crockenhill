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
        Schema::create('sermon_processing_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('processing_id');
            $table->string('step'); // 'analyzing_audio', 'segmenting', 'extracting', 'transcribing', 'analyzing', 'creating'
            $table->enum('status', ['started', 'completed', 'skipped', 'failed', 'cancelled']);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['processing_id', 'step']);
            $table->index(['processing_id', 'status']);
            $table->unique(['processing_id', 'step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sermon_processing_steps');
    }
};

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
        Schema::create('livestream_segments', function (Blueprint $table) {
            $table->id();
            $table->string('processing_id', 36);
            $table->unsignedBigInteger('processing_log_id');
            $table->tinyInteger('segment_index')->unsigned();
            $table->decimal('start_time', 10, 3);
            $table->decimal('end_time', 10, 3);
            $table->decimal('duration', 10, 3);
            $table->enum('classification', ['song', 'speech', 'silence']);
            $table->boolean('is_sermon_segment')->default(false);
            $table->boolean('is_sermon_candidate')->default(false);
            $table->float('avg_rms')->nullable();
            $table->float('peak_rms')->nullable();
            $table->integer('segment_order')->default(0);
            $table->json('metadata')->nullable();
            
            $table->foreign('processing_id')->references('processing_id')->on('livestream_processing_logs')->onDelete('cascade');
            $table->foreign('processing_log_id')->references('id')->on('livestream_processing_logs')->onDelete('cascade');
            $table->timestamps();

            $table->index('processing_id');
            $table->index('processing_log_id');
            $table->index('is_sermon_segment');
            $table->index('is_sermon_candidate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livestream_segments');
    }
};

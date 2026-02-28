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
        Schema::create('service_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_processing_log_id')->constrained('media_processing_logs')->cascadeOnDelete();
            $table->foreignId('church_service_item_id')->nullable()->constrained('church_service_items')->nullOnDelete();
            $table->string('section_type');
            $table->unsignedInteger('section_order');
            $table->string('title')->nullable();
            $table->float('start_time');
            $table->float('end_time');
            $table->float('duration');
            $table->string('status');
            $table->boolean('needs_manual_review')->default(false);
            $table->json('source_segment_ids');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['media_processing_log_id', 'section_order'], 'service_sections_log_order_unique');
            $table->index(['media_processing_log_id', 'section_type'], 'service_sections_log_type_index');
            $table->index('needs_manual_review', 'service_sections_needs_review_index');
            $table->index('church_service_item_id', 'service_sections_church_service_item_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_sections');
    }
};

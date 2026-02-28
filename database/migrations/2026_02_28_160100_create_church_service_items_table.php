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
        Schema::create('church_service_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('church_service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type');
            $table->string('title');
            $table->string('source_title')->nullable();
            $table->string('openlp_search_title')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['church_service_id', 'position']);
            $table->index(['church_service_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_service_items');
    }
};

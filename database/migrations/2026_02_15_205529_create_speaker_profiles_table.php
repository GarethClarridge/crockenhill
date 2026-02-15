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
        Schema::create('speaker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preacher_id')->constrained('preachers')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('model_version', 50);
            $table->json('centroid_embedding');
            $table->unsignedInteger('sample_count')->default(0);
            $table->float('quality_score')->nullable();
            $table->float('accept_threshold')->nullable();
            $table->float('margin_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('preacher_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speaker_profiles');
    }
};

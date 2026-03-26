<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('song_videos')) {
            return;
        }

        Schema::create('song_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_section_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('church_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('video_file_path', 500);
            $table->float('duration')->nullable();
            $table->date('recorded_date')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index(['song_id', 'is_featured']);
            $table->index(['song_id', 'recorded_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_videos');
    }
};

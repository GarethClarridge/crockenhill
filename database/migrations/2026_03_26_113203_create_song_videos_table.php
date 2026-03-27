<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('song_videos')) {
            return;
        }

        $songsIdColumnType = $this->songsIdColumnType();

        Schema::create('song_videos', function (Blueprint $table) use ($songsIdColumnType) {
            $table->id();
            if ($songsIdColumnType === 'unsignedInteger') {
                $table->unsignedInteger('song_id');
            } else {
                $table->unsignedBigInteger('song_id');
            }
            $table->foreign('song_id')->references('id')->on('songs')->cascadeOnDelete();
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

    private function songsIdColumnType(): string
    {
        $songsIdColumn = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['songs', 'id'],
        );

        $songsIdColumnData = is_object($songsIdColumn)
            ? get_object_vars($songsIdColumn)
            : (is_array($songsIdColumn) ? $songsIdColumn : []);

        $songsIdColumnType = $songsIdColumnData['column_type'] ?? null;

        if (! is_string($songsIdColumnType)) {
            return 'unsignedBigInteger';
        }

        return str_contains(strtolower($songsIdColumnType), 'bigint')
            ? 'unsignedBigInteger'
            : 'unsignedInteger';
    }
};

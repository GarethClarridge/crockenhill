<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('song_book_song')) {
            return;
        }

        $songsIdColumnType = $this->songsIdColumnType();

        Schema::create('song_book_song', function (Blueprint $table) use ($songsIdColumnType): void {
            if ($songsIdColumnType === 'unsignedInteger') {
                $table->unsignedInteger('song_id');
            } else {
                $table->unsignedBigInteger('song_id');
            }

            $table->foreignId('song_book_id')->constrained()->cascadeOnDelete();
            $table->string('entry');

            $table->foreign('song_id')->references('id')->on('songs')->cascadeOnDelete();
            $table->unique(['song_id', 'song_book_id', 'entry'], 'song_book_song_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_book_song');
    }

    private function songsIdColumnType(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return 'unsignedBigInteger';
        }

        $songsIdColumn = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type
                FROM information_schema.columns
                WHERE table_schema = database()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1',
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

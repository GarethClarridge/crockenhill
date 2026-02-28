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
        if (! Schema::hasTable('church_service_items') || Schema::hasColumn('church_service_items', 'song_id')) {
            return;
        }

        $songsIdColumnType = $this->songsIdColumnType();

        Schema::table('church_service_items', function (Blueprint $table) use ($songsIdColumnType): void {
            if ($songsIdColumnType === 'unsignedInteger') {
                $table->unsignedInteger('song_id')
                    ->nullable()
                    ->after('openlp_search_title');
            } else {
                $table->unsignedBigInteger('song_id')
                    ->nullable()
                    ->after('openlp_search_title');
            }

            $table->foreign('song_id')->references('id')->on('songs')->nullOnDelete();

            if (! Schema::hasIndex('church_service_items', 'church_service_items_song_id_index')) {
                $table->index('song_id');
            }

            if (! Schema::hasIndex('church_service_items', 'church_service_items_type_song_id_index')) {
                $table->index(['type', 'song_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('church_service_items') || ! Schema::hasColumn('church_service_items', 'song_id')) {
            return;
        }

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropIndex(['type', 'song_id']);
            $table->dropIndex(['song_id']);
            $table->dropConstrainedForeignId('song_id');
        });
    }

    private function songsIdColumnType(): string
    {
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

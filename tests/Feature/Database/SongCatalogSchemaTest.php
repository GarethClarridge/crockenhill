<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_song_catalog_tables_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('songs'));
        $this->assertTrue(Schema::hasTable('song_authors'));
        $this->assertTrue(Schema::hasTable('song_author_song'));
        $this->assertTrue(Schema::hasTable('song_books'));
        $this->assertTrue(Schema::hasTable('song_book_song'));

        $this->assertTrue(Schema::hasColumns('songs', [
            'id',
            'canonical_key',
            'title',
            'alternate_title',
            'lyrics_xml',
            'lyrics_plain',
            'verse_order',
            'copyright',
            'comments',
            'ccli_number',
            'import_metadata',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('church_service_items', [
            'song_id',
        ]));

        $this->assertTrue(Schema::hasIndex('songs', 'songs_canonical_key_unique'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_ccli_number_index'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_deleted_at_index'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_song_id_index'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_type_song_id_index'));
    }

    #[Test]
    public function songs_create_migration_is_safe_when_table_already_exists(): void
    {
        $migration = require database_path('migrations/2026_02_28_190000_create_songs_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasTable('songs'));
    }

    #[Test]
    public function songs_migrations_are_idempotent_when_song_tables_already_exist(): void
    {
        $migrationPaths = [
            '2026_02_28_190000_create_songs_table.php',
            '2026_02_28_190100_create_song_authors_table.php',
            '2026_02_28_190200_create_song_author_song_table.php',
            '2026_02_28_190300_create_song_books_table.php',
            '2026_02_28_190400_create_song_book_song_table.php',
            '2026_02_28_190500_add_song_id_to_church_service_items_table.php',
        ];

        foreach ($migrationPaths as $migrationPath) {
            $migration = require database_path('migrations/'.$migrationPath);
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('songs'));
        $this->assertTrue(Schema::hasTable('song_authors'));
        $this->assertTrue(Schema::hasTable('song_author_song'));
        $this->assertTrue(Schema::hasTable('song_books'));
        $this->assertTrue(Schema::hasTable('song_book_song'));
        $this->assertTrue(Schema::hasColumn('church_service_items', 'song_id'));
    }

    #[Test]
    public function reconcile_song_catalog_schema_migration_repairs_legacy_songs_shape(): void
    {
        Schema::table('songs', function (Blueprint $table): void {
            $table->dropUnique('songs_canonical_key_unique');
            $table->dropIndex('songs_ccli_number_index');
            $table->dropIndex('songs_deleted_at_index');
            $table->dropColumn('lyrics_plain');
        });

        DB::table('songs')->insert([
            [
                'canonical_key' => 'legacy-song-key',
                'title' => '',
                'lyrics_xml' => '<song><lyrics /></song>',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical_key' => 'legacy-song-key',
                'title' => '',
                'lyrics_xml' => '<song><lyrics /></song>',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_02_28_233313_reconcile_song_catalog_schema.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('songs', 'lyrics_plain'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_canonical_key_unique'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_ccli_number_index'));
        $this->assertTrue(Schema::hasIndex('songs', 'songs_deleted_at_index'));

        $songCount = DB::table('songs')->count();
        $distinctCanonicalKeyCount = DB::table('songs')->distinct()->count('canonical_key');

        $this->assertSame($songCount, $distinctCanonicalKeyCount);
        $this->assertSame(0, DB::table('songs')->where('title', '')->count());
    }

    #[Test]
    public function song_fk_migrations_match_legacy_integer_song_primary_key_type(): void
    {
        Schema::dropIfExists('song_author_song');
        Schema::dropIfExists('song_book_song');

        if (Schema::hasColumn('church_service_items', 'song_id')) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('song_id');
            });
        }

        if (Schema::hasColumn('song_videos', 'song_id')) {
            Schema::table('song_videos', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('song_id');
            });
        }

        DB::statement('ALTER TABLE songs MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');

        $songAuthorSongMigration = require database_path('migrations/2026_02_28_190200_create_song_author_song_table.php');
        $songBookSongMigration = require database_path('migrations/2026_02_28_190400_create_song_book_song_table.php');
        $addSongIdMigration = require database_path('migrations/2026_02_28_190500_add_song_id_to_church_service_items_table.php');

        $songAuthorSongMigration->up();
        $songBookSongMigration->up();
        $addSongIdMigration->up();

        $songsIdColumnType = $this->columnType('songs', 'id');
        $this->assertSame($songsIdColumnType, $this->columnType('song_author_song', 'song_id'));
        $this->assertSame($songsIdColumnType, $this->columnType('song_book_song', 'song_id'));
        $this->assertSame($songsIdColumnType, $this->columnType('church_service_items', 'song_id'));
    }

    #[Test]
    public function force_deleting_song_sets_song_id_to_null_on_church_service_item(): void
    {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $song->forceDelete();
        $item->refresh();

        $this->assertNull($item->song_id);
    }

    private function columnType(string $tableName, string $columnName): ?string
    {
        $column = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type
                FROM information_schema.columns
                WHERE table_schema = database()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1',
            [$tableName, $columnName],
        );

        $columnData = is_object($column)
            ? get_object_vars($column)
            : (is_array($column) ? $column : []);

        $columnType = $columnData['column_type'] ?? null;

        return is_string($columnType)
            ? strtolower($columnType)
            : null;
    }
}

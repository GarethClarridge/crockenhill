<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Sermon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorrectiveSchemaMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function sermonLivestreamForeignKeyMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_03_12_145040_add_foreign_key_to_sermons_livestream_processing_id.php');

        return $migration;
    }

    private function meetingsLocationMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_02_26_221409_make_meetings_location_nullable_if_required.php');

        return $migration;
    }

    private function passwordResetMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_02_26_221409_normalize_password_reset_tokens_email_key.php');

        return $migration;
    }

    private function sermonAudioFilePathCheckMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_04_20_053821_add_audio_file_path_check_to_sermons_table.php');

        return $migration;
    }

    private function redundantLivestreamIndexMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_06_18_120000_drop_redundant_church_service_items_livestream_index.php');

        return $migration;
    }

    private function redundantFkIndexesMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_06_18_130000_drop_redundant_fk_indexes_from_media_processing_logs_and_speaker_samples.php');

        return $migration;
    }

    private function isMySql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    private function isColumnNullable(string $table, string $column): bool
    {
        $nullable = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable');

        return $nullable === 'YES';
    }

    private function hasPrimaryKeyOnEmail(): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'password_reset_tokens')
            ->where('column_name', 'email')
            ->where('constraint_name', 'PRIMARY')
            ->exists();
    }

    private function columnDefinition(string $table, string $column): ?object
    {
        return DB::table('information_schema.columns')
            ->selectRaw('COLUMN_TYPE as column_type, COLLATION_NAME as collation_name, IS_NULLABLE as is_nullable')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->first();
    }

    private function hasForeignKey(string $table, string $column, string $referencedTable): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('referenced_table_name', $referencedTable)
            ->exists();
    }

    private function hasCheckConstraint(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    #[Test]
    public function it_makes_meetings_location_nullable_when_schema_has_drifted(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This drift simulation requires MySQL.');
        }

        DB::statement('ALTER TABLE meetings MODIFY location VARCHAR(75) NOT NULL');
        $this->assertFalse($this->isColumnNullable('meetings', 'location'));

        $this->meetingsLocationMigration()->up();

        $this->assertTrue($this->isColumnNullable('meetings', 'location'));
    }

    #[Test]
    public function it_normalizes_password_reset_tokens_email_key_when_schema_has_drifted(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This drift simulation requires MySQL.');
        }

        Schema::drop('password_reset_tokens');
        DB::statement('CREATE TABLE password_reset_tokens (email VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (email))');

        $this->assertTrue($this->hasPrimaryKeyOnEmail());
        $this->assertFalse(Schema::hasIndex('password_reset_tokens', 'password_reset_tokens_email_index'));

        $this->passwordResetMigration()->up();

        $this->assertFalse($this->hasPrimaryKeyOnEmail());
        $this->assertTrue(Schema::hasIndex('password_reset_tokens', 'password_reset_tokens_email_index'));
    }

    #[Test]
    public function sermon_audio_file_path_check_migration_normalizes_blank_paths_before_adding_constraint(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This drift simulation requires MySQL.');
        }

        $this->sermonAudioFilePathCheckMigration()->down();

        $trimmedSermon = Sermon::factory()->create([
            'audio_file_path' => ' sermons/archive/drifted.mp3 ',
        ]);
        $blankSermon = Sermon::factory()->create([
            'audio_file_path' => '',
        ]);

        DB::statement('ALTER TABLE sermons MODIFY audio_file_path VARCHAR(255) NOT NULL');

        $this->sermonAudioFilePathCheckMigration()->up();

        $this->assertSame('sermons/archive/drifted.mp3', $trimmedSermon->fresh()->audio_file_path);
        $this->assertNull($blankSermon->fresh()->audio_file_path);
        $this->assertTrue($this->hasCheckConstraint('sermons', 'sermons_audio_file_path_format_check'));
    }

    #[Test]
    public function it_drops_the_redundant_church_service_items_livestream_index_while_retaining_foreign_key_coverage(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('Foreign-key auto-indexing behaviour requires MySQL.');
        }

        // The foreign key supplies its own backing index, so the column remains indexed
        // even once the duplicate explicit index is removed.
        $this->assertTrue($this->hasForeignKey('church_service_items', 'livestream_service_section_id', 'service_sections'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_foreign'));

        // Simulate an environment that applied the original (now-corrected) index migration.
        if (! Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index')) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->index('livestream_service_section_id', 'church_service_items_livestream_service_section_id_index');
            });
        }
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'));

        $this->redundantLivestreamIndexMigration()->up();

        $this->assertFalse(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'));
        // Foreign-key backing index (and thus column coverage) is left intact.
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_foreign'));

        $this->redundantLivestreamIndexMigration()->down();

        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'));
    }

    #[Test]
    public function it_leaves_the_livestream_index_in_place_when_it_is_the_foreign_keys_only_backing_index(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('Foreign-key auto-indexing behaviour requires MySQL.');
        }

        // Reproduce the production state: the explicit index exists and the foreign key has adopted it
        // as its sole backing index (no auto-created `_foreign` index). Adding the explicit index, then
        // dropping the `_foreign` one, makes MySQL reassign the foreign key to the explicit index.
        if (! Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index')) {
            Schema::table('church_service_items', function (Blueprint $table): void {
                $table->index('livestream_service_section_id', 'church_service_items_livestream_service_section_id_index');
            });
        }
        DB::statement('ALTER TABLE church_service_items DROP INDEX church_service_items_livestream_service_section_id_foreign');

        $this->assertFalse(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_foreign'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'));

        // Dropping the explicit index here would fail with errno 1553, so the migration must skip it.
        $this->redundantLivestreamIndexMigration()->up();

        $this->assertTrue($this->hasForeignKey('church_service_items', 'livestream_service_section_id', 'service_sections'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'));
    }

    #[Test]
    public function it_drops_redundant_fk_duplicate_indexes_while_retaining_foreign_key_coverage(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('Foreign-key auto-indexing behaviour requires MySQL.');
        }

        $cases = [
            ['media_processing_logs', 'sermon_id', 'sermons', 'media_processing_logs_sermon_id_index', 'media_processing_logs_sermon_id_foreign'],
            ['media_processing_logs', 'owner_user_id', 'users', 'media_processing_logs_owner_user_id_index', 'media_processing_logs_owner_user_id_foreign'],
            ['media_processing_logs', 'church_service_id', 'church_services', 'media_processing_logs_church_service_id_index', 'media_processing_logs_church_service_id_foreign'],
            ['speaker_samples', 'media_processing_log_id', 'media_processing_logs', 'speaker_samples_media_processing_log_id_index', 'speaker_samples_media_processing_log_id_foreign'],
        ];

        // The foreign keys supply their own backing indexes, so each column stays indexed
        // even once the duplicate explicit index is removed. Simulate an environment that
        // applied the original (now-corrected) index migration.
        foreach ($cases as [$table, $column, $referencedTable, $explicitIndex, $foreignIndex]) {
            $this->assertTrue($this->hasForeignKey($table, $column, $referencedTable));
            $this->assertTrue(Schema::hasIndex($table, $foreignIndex));

            if (! Schema::hasIndex($table, $explicitIndex)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $explicitIndex): void {
                    $blueprint->index($column, $explicitIndex);
                });
            }
            $this->assertTrue(Schema::hasIndex($table, $explicitIndex));
        }

        $this->redundantFkIndexesMigration()->up();

        foreach ($cases as [$table, , , $explicitIndex, $foreignIndex]) {
            $this->assertFalse(Schema::hasIndex($table, $explicitIndex));
            // Foreign-key backing index (and thus column coverage) is left intact.
            $this->assertTrue(Schema::hasIndex($table, $foreignIndex));
        }

        $this->redundantFkIndexesMigration()->down();

        foreach ($cases as [$table, , , $explicitIndex]) {
            $this->assertTrue(Schema::hasIndex($table, $explicitIndex));
        }
    }

    #[Test]
    public function it_leaves_fk_duplicate_indexes_in_place_when_they_are_the_foreign_keys_only_backing_index(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('Foreign-key auto-indexing behaviour requires MySQL.');
        }

        $cases = [
            ['media_processing_logs', 'sermon_id', 'sermons', 'media_processing_logs_sermon_id_index', 'media_processing_logs_sermon_id_foreign'],
            ['speaker_samples', 'media_processing_log_id', 'media_processing_logs', 'speaker_samples_media_processing_log_id_index', 'speaker_samples_media_processing_log_id_foreign'],
        ];

        // Reproduce the production state for each foreign key: the explicit index exists and the foreign
        // key has adopted it as its sole backing index (no auto-created `_foreign` index).
        foreach ($cases as [$table, $column, , $explicitIndex, $foreignIndex]) {
            if (! Schema::hasIndex($table, $explicitIndex)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $explicitIndex): void {
                    $blueprint->index($column, $explicitIndex);
                });
            }
            DB::statement("ALTER TABLE {$table} DROP INDEX {$foreignIndex}");

            $this->assertFalse(Schema::hasIndex($table, $foreignIndex));
            $this->assertTrue(Schema::hasIndex($table, $explicitIndex));
        }

        // Dropping any explicit index here would fail with errno 1553, so the migration must skip them.
        $this->redundantFkIndexesMigration()->up();

        foreach ($cases as [$table, $column, $referencedTable, $explicitIndex]) {
            $this->assertTrue($this->hasForeignKey($table, $column, $referencedTable));
            $this->assertTrue(Schema::hasIndex($table, $explicitIndex));
        }
    }

    #[Test]
    public function sermon_livestream_foreign_key_migration_is_reversible(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This reversibility check requires MySQL.');
        }

        $this->assertTrue($this->hasForeignKey('sermons', 'livestream_processing_id', 'media_processing_logs'));

        $this->sermonLivestreamForeignKeyMigration()->down();

        $columnAfterDown = $this->columnDefinition('sermons', 'livestream_processing_id');

        $this->assertNotNull($columnAfterDown);
        $this->assertSame('varchar(36)', $columnAfterDown->column_type);
        $this->assertSame('utf8mb4_unicode_ci', $columnAfterDown->collation_name);
        $this->assertSame('YES', $columnAfterDown->is_nullable);
        $this->assertFalse($this->hasForeignKey('sermons', 'livestream_processing_id', 'media_processing_logs'));

        $this->sermonLivestreamForeignKeyMigration()->up();

        $columnAfterUp = $this->columnDefinition('sermons', 'livestream_processing_id');

        $this->assertNotNull($columnAfterUp);
        $this->assertSame('char(36)', $columnAfterUp->column_type);
        $this->assertSame('utf8mb4_unicode_ci', $columnAfterUp->collation_name);
        $this->assertSame('YES', $columnAfterUp->is_nullable);
        $this->assertTrue($this->hasForeignKey('sermons', 'livestream_processing_id', 'media_processing_logs'));
    }
}

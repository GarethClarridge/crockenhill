<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Sermon;
use Illuminate\Database\Migrations\Migration;
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

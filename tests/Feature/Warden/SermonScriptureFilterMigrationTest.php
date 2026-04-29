<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonScriptureFilterMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const INTEGRITY_MIGRATION = '2026_04_22_054106_add_integrity_checks_to_sermon_scripture_filters_table.php';

    #[Test]
    public function migration_handles_potential_duplicate_collisions_during_normalization(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Migration collision logic is MySQL-specific.');
        }

        $this->rollbackIntegrityMigration();

        try {
            $sermon = Sermon::factory()->create();

            DB::table('sermon_scripture_filters')->insert([
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => 'John',
                    'bible_chapter' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => ' John ', // Collides with 'John' after TRIM()
                    'bible_chapter' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->runIntegrityMigration();

            $count = DB::table('sermon_scripture_filters')
                ->where('sermon_id', $sermon->id)
                ->where('bible_book', 'John')
                ->where('bible_chapter', 1)
                ->count();

            $this->assertSame(1, $count);
        } finally {
            $this->runIntegrityMigration();
        }
    }

    #[Test]
    public function migration_cleans_up_invalid_data_before_adding_constraints(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Migration integrity cleanup logic is MySQL-specific.');
        }

        $this->rollbackIntegrityMigration();

        try {
            $sermon = Sermon::factory()->create();
            DB::table('sermon_scripture_filters')->where('sermon_id', $sermon->id)->delete();

            DB::table('sermon_scripture_filters')->insert([
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => '',
                    'bible_chapter' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => ' ',
                    'bible_chapter' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => 'John',
                    'bible_chapter' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->runIntegrityMigration();

            $count = DB::table('sermon_scripture_filters')
                ->where('sermon_id', $sermon->id)
                ->count();

            $this->assertSame(0, $count);
        } finally {
            $this->runIntegrityMigration();
        }
    }

    private function rollbackIntegrityMigration(): void
    {
        DB::statement('ALTER TABLE sermon_scripture_filters DROP CHECK sermon_scripture_filters_bible_book_format_check');
        DB::statement('ALTER TABLE sermon_scripture_filters DROP CHECK sermon_scripture_filters_bible_chapter_check');
    }

    private function runIntegrityMigration(): void
    {
        // Drop first (idempotent restore — constraints may already exist if migration ran successfully)
        foreach (['sermon_scripture_filters_bible_book_format_check', 'sermon_scripture_filters_bible_chapter_check'] as $name) {
            try {
                DB::statement("ALTER TABLE sermon_scripture_filters DROP CHECK {$name}");
            } catch (\Throwable) {
                // Constraint may already be gone — that's fine
            }
        }

        $migration = require database_path('migrations/'.self::INTEGRITY_MIGRATION);
        $migration->up();
    }
}

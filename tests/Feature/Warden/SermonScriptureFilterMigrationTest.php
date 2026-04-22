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

    #[Test]
    public function migration_handles_potential_duplicate_collisions_during_normalization(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Migration collision logic is MySQL-specific.');
        }

        // 1. Rollback the migration to set up the collision state
        $this->artisan('migrate:rollback', ['--step' => 1]);

        try {
            $sermon = Sermon::factory()->create();

            // 2. Insert records that will collide when TRIM() is applied
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

            // 3. Run the migration and ensure it succeeds
            $this->artisan('migrate');

            // 4. Verify that the collision was handled (one record should be deleted)
            $count = DB::table('sermon_scripture_filters')
                ->where('sermon_id', $sermon->id)
                ->where('bible_book', 'John')
                ->where('bible_chapter', 1)
                ->count();

            $this->assertEquals(1, $count);

        } finally {
            // Ensure we are in a consistent state for other tests
        }
    }

    #[Test]
    public function migration_cleans_up_invalid_data_before_adding_constraints(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Migration integrity cleanup logic is MySQL-specific.');
        }

        // 1. Rollback the migration
        $this->artisan('migrate:rollback', ['--step' => 1]);

        try {
            $sermon = Sermon::factory()->create();
            // Clear any filters created by observers to have a clean slate
            DB::table('sermon_scripture_filters')->where('sermon_id', $sermon->id)->delete();

            // 2. Insert invalid records
            DB::table('sermon_scripture_filters')->insert([
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => '', // Empty book
                    'bible_chapter' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => ' ', // Whitespace book
                    'bible_chapter' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sermon_id' => $sermon->id,
                    'bible_book' => 'John',
                    'bible_chapter' => 0, // Invalid chapter
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // 3. Run the migration and ensure it succeeds
            $this->artisan('migrate');

            // 4. Verify that invalid records were deleted
            $count = DB::table('sermon_scripture_filters')
                ->where('sermon_id', $sermon->id)
                ->count();

            $this->assertEquals(0, $count);

        } finally {
            // Ensure we are in a consistent state for other tests
        }
    }
}

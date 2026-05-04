<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Song;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_validates_song_slug_format(): void
    {
        $rules = Song::validationRules();

        $this->assertTrue(Validator::make(['slug' => 'valid-slug-123'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'valid_slug_too'], ['slug' => $rules['slug']])->passes(), 'Underscores should be rejected');
        $this->assertFalse(Validator::make(['slug' => 'invalid slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid!slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'UPPERCASE-slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => ''], ['slug' => $rules['slug']])->passes());
    }

    #[Test]
    public function database_rejects_null_song_slug(): void
    {
        $this->expectException(QueryException::class);

        // We use DB directly to bypass any Eloquent safeguards if they exist
        DB::table('songs')->insert([
            'slug' => null,
            'canonical_key' => 'test-key',
            'title' => 'Test Song',
            'lyrics_xml' => '<song></song>',
        ]);
    }

    #[Test]
    public function database_rejects_invalid_song_slug_format(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        Song::factory()->create([
            'slug' => 'Invalid Slug!',
        ]);
    }

    #[Test]
    public function migration_repairs_invalid_slugs(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        // Drop the constraint directly so we can insert invalid data to test the migration repair logic
        DB::statement('ALTER TABLE songs DROP CHECK songs_slug_format_check');

        try {
            DB::table('songs')->insert([
                'slug' => 'invalid_slug_with_underscore',
                'canonical_key' => 'repair-test-key',
                'title' => 'Repair Test',
                'lyrics_xml' => '<song></song>',
            ]);

            // Run the migration's up() to exercise repair logic
            $migration = require database_path('migrations/2026_04_28_150435_fortify_song_slug_integrity.php');
            $migration->up();

            $song = Song::where('canonical_key', 'repair-test-key')->first();
            $this->assertEquals('invalid-slug-with-underscore', $song->slug);
        } finally {
            // Ensure constraint is restored even if something fails
            try {
                DB::statement('ALTER TABLE songs DROP CHECK songs_slug_format_check');
            } catch (\Throwable) {
            }
            $pattern = '^[a-z0-9]+(?:-[a-z0-9]+)*$';
            DB::statement("ALTER TABLE songs ADD CONSTRAINT songs_slug_format_check CHECK (REGEXP_LIKE(slug, '{$pattern}', 'c'))");
        }
    }

    #[Test]
    public function database_rejects_empty_song_slug(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        Song::factory()->create([
            'slug' => '',
        ]);
    }
}

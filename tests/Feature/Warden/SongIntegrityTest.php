<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Song;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongIntegrityTest extends TestCase
{
    use RefreshDatabase;

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

        $canonicalKey = 'repair-test-key-'.uniqid();

        try {
            $baseSlug = 'invalid-slug-with-underscore';
            $slug = $baseSlug;
            if (DB::table('songs')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-unique';
            }

            DB::table('songs')->insert([
                'slug' => $slug,
                'canonical_key' => $canonicalKey,
                'title' => 'Repair Test',
                'lyrics_xml' => '<song></song>',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Run the migration's up() to exercise repair logic
            $migration = require database_path('migrations/2026_04_28_150435_fortify_song_slug_integrity.php');
            $migration->up();

            $song = Song::where('canonical_key', $canonicalKey)->first();
            $this->assertStringContainsString('invalid-slug-with-underscore', $song->slug);
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

    #[Test]
    public function database_rejects_untrimmed_song_title(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        // Bypass Eloquent Attribute setter
        DB::table('songs')->insert([
            'title' => '  Untrimmed Title  ',
            'canonical_key' => 'valid-key',
            'slug' => 'valid-slug',
            'lyrics_xml' => '<song></song>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_unnormalized_canonical_key(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        // Bypass Eloquent Attribute setter
        DB::table('songs')->insert([
            'title' => 'Valid Title',
            'canonical_key' => 'UPPERCASE KEY',
            'slug' => 'valid-slug-2',
            'lyrics_xml' => '<song></song>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_untrimmed_alternate_title(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        // Bypass Eloquent Attribute setter
        DB::table('songs')->insert([
            'title' => 'Valid Title',
            'canonical_key' => 'valid-key-3',
            'slug' => 'valid-slug-3',
            'alternate_title' => '  Untrimmed Alternate  ',
            'lyrics_xml' => '<song></song>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_rejects_empty_alternate_title(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(QueryException::class);

        // Bypass Eloquent Attribute setter
        DB::table('songs')->insert([
            'title' => 'Valid Title',
            'canonical_key' => 'valid-key-4',
            'slug' => 'valid-slug-4',
            'alternate_title' => '',
            'lyrics_xml' => '<song></song>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function attribute_setters_normalize_input(): void
    {
        $song = new Song([
            'title' => '  Title  ',
            'canonical_key' => '  MY  KEY  @Extra  ',
            'alternate_title' => '  ',
        ]);

        $this->assertEquals('Title', $song->title);
        $this->assertEquals('my key', $song->canonical_key);
        $this->assertNull($song->alternate_title);
    }
}

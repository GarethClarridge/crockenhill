<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->expectException(\Illuminate\Database\QueryException::class);

        // We use DB directly to bypass any Eloquent safeguards if they exist
        \Illuminate\Support\Facades\DB::table('songs')->insert([
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

        $this->expectException(\Illuminate\Database\QueryException::class);

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

        // 1. Create a song with an invalid slug (bypassing Eloquent if needed, but here we can just rollback)
        // Actually, we'll test the repair logic by rolling back and then migrating again.

        // We'll create a song with an underscore in the slug, which is invalid per the pattern.
        // But the constraint is currently active. So we have to rollback first.
        \Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--step' => 1]);

        \Illuminate\Support\Facades\DB::table('songs')->insert([
            'slug' => 'invalid_slug_with_underscore',
            'canonical_key' => 'repair-test-key',
            'title' => 'Repair Test',
            'lyrics_xml' => '<song></song>',
        ]);

        // 2. Run the migration
        \Illuminate\Support\Facades\Artisan::call('migrate');

        // 3. Verify it was repaired
        $song = \App\Models\Song::where('canonical_key', 'repair-test-key')->first();
        $this->assertEquals('invalid-slug-with-underscore', $song->slug);
    }

    #[Test]
    public function database_rejects_empty_song_slug(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Database integrity tests require MySQL');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        Song::factory()->create([
            'slug' => '',
        ]);
    }
}

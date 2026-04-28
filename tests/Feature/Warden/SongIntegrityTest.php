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

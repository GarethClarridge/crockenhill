<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Song;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongTitleIntegrityTest extends TestCase
{
    #[Test]
    public function it_has_an_index_on_the_songs_title_column(): void
    {
        $indices = DB::select('SHOW INDEX FROM songs');
        $hasTitleIndex = collect($indices)->contains('Column_name', 'title');

        $this->assertTrue($hasTitleIndex, 'The songs table should have an index on the title column.');
    }

    #[Test]
    public function it_validates_that_the_song_title_does_not_exceed_the_database_limit(): void
    {
        $rules = Song::validationRules();

        $this->assertArrayHasKey('title', $rules);
        $this->assertContains('max:255', $rules['title'], 'The song title validation rule should match the widened database column length of 255.');

        $longTitle = str_repeat('a', 256);
        $validator = Validator::make(['title' => $longTitle], ['title' => $rules['title']]);

        $this->assertTrue($validator->fails(), 'Validation should fail for titles longer than 255 characters.');
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_valid_song_titles(): void
    {
        $rules = Song::validationRules();
        $validTitle = str_repeat('a', 255);

        $validator = Validator::make(['title' => $validTitle], ['title' => $rules['title']]);

        $this->assertFalse($validator->fails(), 'Validation should pass for a valid title within 255 characters.');
    }

    #[Test]
    public function it_has_a_widen_database_column(): void
    {
        $length = DB::selectOne("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_name = 'songs' AND column_name = 'title' AND table_schema = DATABASE()")->CHARACTER_MAXIMUM_LENGTH;

        $this->assertEquals(255, $length, 'The songs.title column should have been widened to 255 characters.');
    }
}

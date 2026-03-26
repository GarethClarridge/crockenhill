<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use App\Models\SongVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canonicalize_key_strips_openlp_alternate_search_text_after_at_symbol(): void
    {
        $result = Song::canonicalizeKey('  Who   Am I@Who   You Say I Am  ');

        $this->assertSame('who am i', $result);
    }

    #[Test]
    public function canonicalize_key_handles_trailing_at_symbol(): void
    {
        $this->assertSame('amazing grace', Song::canonicalizeKey('amazing grace@'));
    }

    #[Test]
    public function canonicalize_key_handles_input_without_at_symbol(): void
    {
        $this->assertSame('be thou my vision', Song::canonicalizeKey('  Be Thou   My Vision  '));
    }

    #[Test]
    public function song_has_expected_relationships(): void
    {
        $song = Song::factory()->create();
        $author = SongAuthor::factory()->create([
            'display_name' => 'John Writer',
        ]);
        $book = SongBook::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'song_id' => $song->id,
        ]);

        $song->authors()->attach($author->id, ['author_type' => 'words']);
        $song->books()->attach($book->id, ['entry' => '32']);

        $song->refresh();

        $this->assertCount(1, $song->authors);
        $this->assertSame('words', $song->authors->first()?->pivot->author_type);
        $this->assertCount(1, $song->books);
        $this->assertSame('32', $song->books->first()?->pivot->entry);
        $this->assertCount(1, $song->churchServiceItems);
        $this->assertSame($song->id, $item->song?->id);
    }

    #[Test]
    public function song_has_videos_relationship(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->count(3)->create(['song_id' => $song->id]);

        $this->assertCount(3, $song->videos);
        $this->assertInstanceOf(SongVideo::class, $song->videos->first());
    }

    #[Test]
    public function videos_are_ordered_by_recorded_date_descending(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-01-01']);
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-06-01']);
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-03-01']);

        $dates = $song->videos->pluck('recorded_date')->map->format('Y-m-d')->toArray();

        $this->assertSame(['2025-06-01', '2025-03-01', '2025-01-01'], $dates);
    }

    #[Test]
    public function videos_relationship_sorts_manual_uploads_with_null_date_last(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->manual()->create(['song_id' => $song->id]);
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-06-01']);
        SongVideo::factory()->manual()->create(['song_id' => $song->id]);
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-01-01']);

        $dates = $song->videos->pluck('recorded_date')->map(fn ($d) => $d?->format('Y-m-d'))->toArray();

        $this->assertSame(['2025-06-01', '2025-01-01', null, null], $dates);
    }

    #[Test]
    public function featured_video_relationship_returns_featured_video(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->create(['song_id' => $song->id, 'is_featured' => false]);
        $featured = SongVideo::factory()->featured()->create(['song_id' => $song->id]);

        $this->assertNotNull($song->featuredVideo);
        $this->assertSame($featured->id, $song->featuredVideo->id);
    }

    #[Test]
    public function display_video_returns_featured_video_first(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-06-01', 'is_featured' => false]);
        $featured = SongVideo::factory()->featured()->create(['song_id' => $song->id, 'recorded_date' => '2025-01-01']);

        $display = $song->displayVideo();

        $this->assertNotNull($display);
        $this->assertSame($featured->id, $display->id);
    }

    #[Test]
    public function display_video_falls_back_to_most_recent_dated_video(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-01-01', 'is_featured' => false]);
        $recent = SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-06-01', 'is_featured' => false]);

        $display = $song->displayVideo();

        $this->assertNotNull($display);
        $this->assertSame($recent->id, $display->id);
    }

    #[Test]
    public function display_video_manual_uploads_do_not_outrank_dated_videos(): void
    {
        $song = Song::factory()->create();
        $dated = SongVideo::factory()->create(['song_id' => $song->id, 'recorded_date' => '2025-01-01', 'is_featured' => false]);
        SongVideo::factory()->manual()->create(['song_id' => $song->id]);

        $display = $song->displayVideo();

        $this->assertNotNull($display);
        $this->assertSame($dated->id, $display->id);
    }

    #[Test]
    public function display_video_returns_null_for_song_with_only_manual_uploads_and_no_featured(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->manual()->create(['song_id' => $song->id]);
        SongVideo::factory()->manual()->create(['song_id' => $song->id]);

        $display = $song->displayVideo();

        $this->assertNull($display);
    }
}

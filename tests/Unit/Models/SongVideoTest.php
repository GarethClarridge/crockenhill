<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongVideoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_is_featured_to_boolean(): void
    {
        $video = SongVideo::factory()->create(['is_featured' => true]);

        $this->assertTrue($video->is_featured);
        $this->assertIsBool($video->is_featured);
    }

    #[Test]
    public function it_casts_recorded_date_to_date(): void
    {
        $video = SongVideo::factory()->create(['recorded_date' => '2025-06-15']);

        $this->assertInstanceOf(Carbon::class, $video->recorded_date);
        $this->assertSame('2025-06-15', $video->recorded_date->format('Y-m-d'));
    }

    #[Test]
    public function it_casts_duration_to_float(): void
    {
        $video = SongVideo::factory()->create(['duration' => 123.45]);

        $this->assertIsFloat($video->duration);
        $this->assertEqualsWithDelta(123.45, $video->duration, 0.001);
    }

    #[Test]
    public function is_featured_helper_returns_boolean(): void
    {
        $featured = SongVideo::factory()->featured()->create();
        $unfeatured = SongVideo::factory()->create(['is_featured' => false]);

        $this->assertTrue($featured->isFeatured());
        $this->assertFalse($unfeatured->isFeatured());
    }

    #[Test]
    public function featured_scope_returns_only_featured_videos(): void
    {
        $song = Song::factory()->create();
        SongVideo::factory()->count(2)->create(['song_id' => $song->id, 'is_featured' => false]);
        $featured = SongVideo::factory()->featured()->create(['song_id' => $song->id]);

        $results = SongVideo::featured()->get();

        $this->assertCount(1, $results);
        $this->assertSame($featured->id, $results->first()->id);
    }

    #[Test]
    public function for_song_scope_filters_by_song_id(): void
    {
        $song1 = Song::factory()->create();
        $song2 = Song::factory()->create();
        SongVideo::factory()->count(2)->create(['song_id' => $song1->id]);
        SongVideo::factory()->create(['song_id' => $song2->id]);

        $results = SongVideo::forSong($song1->id)->get();

        $this->assertCount(2, $results);
        $results->each(fn ($v) => $this->assertSame($song1->id, $v->song_id));
    }

    #[Test]
    public function it_belongs_to_a_song(): void
    {
        $song = Song::factory()->create();
        $video = SongVideo::factory()->create(['song_id' => $song->id]);

        $this->assertInstanceOf(Song::class, $video->song);
        $this->assertSame($song->id, $video->song->id);
    }

    #[Test]
    public function it_belongs_to_a_service_section(): void
    {
        $section = ServiceSection::factory()->create();
        $video = SongVideo::factory()->create(['service_section_id' => $section->id]);

        $this->assertInstanceOf(ServiceSection::class, $video->serviceSection);
        $this->assertSame($section->id, $video->serviceSection->id);
    }

    #[Test]
    public function service_section_is_nullable(): void
    {
        $video = SongVideo::factory()->create(['service_section_id' => null]);

        $this->assertNull($video->serviceSection);
    }

    #[Test]
    public function it_belongs_to_a_church_service(): void
    {
        $service = ChurchService::factory()->create();
        $video = SongVideo::factory()->create(['church_service_id' => $service->id]);

        $this->assertInstanceOf(ChurchService::class, $video->churchService);
        $this->assertSame($service->id, $video->churchService->id);
    }

    #[Test]
    public function church_service_is_nullable(): void
    {
        $video = SongVideo::factory()->create(['church_service_id' => null]);

        $this->assertNull($video->churchService);
    }
}

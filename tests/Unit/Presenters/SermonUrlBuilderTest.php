<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonUrlBuilder;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonUrlBuilderTest extends TestCase
{
    private SermonStorageService&MockInterface $storageService;

    private SermonExposurePolicy&MockInterface $exposurePolicy;

    private SermonViewPresenter&MockInterface $presenter;

    private SermonUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageService = Mockery::mock(SermonStorageService::class);
        $this->exposurePolicy = Mockery::mock(SermonExposurePolicy::class);
        $this->presenter = Mockery::mock(SermonViewPresenter::class);
        $this->builder = new SermonUrlBuilder($this->storageService, $this->exposurePolicy);
    }

    #[Test]
    public function it_returns_audio_url_when_a_file_is_present(): void
    {
        $sermon = Sermon::factory()->make(['audio_file_path' => 'sermons/audio.mp3']);
        $this->storageService->shouldReceive('getAudioDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/audio.mp3');

        $this->assertSame('https://cdn.example.com/audio.mp3', $this->builder->audioUrl($this->presenter, $sermon));
    }

    #[Test]
    public function it_returns_null_audio_url_when_no_file_is_present(): void
    {
        $sermon = Sermon::factory()->make(['audio_file_path' => null]);

        $this->assertNull($this->builder->audioUrl($this->presenter, $sermon));
    }

    #[Test]
    public function it_returns_video_url_only_when_exposed(): void
    {
        $sermon = Sermon::factory()->make();
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->with($sermon)->andReturn(true);
        $this->storageService->shouldReceive('getVideoDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/video.mp4');

        $this->assertSame('https://cdn.example.com/video.mp4', $this->builder->videoUrl($this->presenter, $sermon));
    }

    #[Test]
    public function it_returns_null_video_url_when_not_exposed(): void
    {
        $sermon = Sermon::factory()->make();
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->with($sermon)->andReturn(false);

        $this->assertNull($this->builder->videoUrl($this->presenter, $sermon));
    }

    #[Test]
    public function it_returns_thumbnail_url_when_exposed_and_present(): void
    {
        $sermon = Sermon::factory()->make(['thumbnail_file_path' => 'thumb.webp']);
        $this->exposurePolicy->shouldReceive('shouldExposeThumbnail')->with($sermon)->andReturn(true);
        $this->storageService->shouldReceive('getThumbnailDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/thumb.webp');

        $this->assertSame('https://cdn.example.com/thumb.webp', $this->builder->thumbnailUrl($this->presenter, $sermon));
    }

    #[Test]
    public function it_falls_back_to_the_primary_thumbnail_via_the_presenter_when_metadata_is_unloaded(): void
    {
        // A sermon whose thumbnail_metadata column was not selected (listing query)
        // but which has a primary thumbnail.
        $sermon = Sermon::factory()->make(['thumbnail_file_path' => 'thumb.webp']);
        $sermon->offsetUnset('thumbnail_metadata');

        $this->exposurePolicy->shouldReceive('shouldExposeThumbnail')->with($sermon)->andReturn(true);
        $this->presenter->shouldReceive('thumbnailUrl')->with($sermon)->andReturn('https://cdn.example.com/thumb.webp');

        $this->assertSame(
            'https://cdn.example.com/thumb.webp',
            $this->builder->plainThumbnailUrl($this->presenter, $sermon),
        );
    }

    #[Test]
    public function it_returns_transcript_url_when_a_transcript_exists(): void
    {
        $sermon = Sermon::factory()->make(['slug' => 'a-sermon', 'transcript_file_path' => 'transcripts/a-sermon.txt']);
        $this->exposurePolicy->shouldNotReceive('shouldExposeThumbnail');

        $this->assertSame(
            route('sermons.transcript', ['sermon' => 'a-sermon']),
            $this->builder->transcriptUrl($this->presenter, $sermon),
        );
    }
}

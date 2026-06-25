<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use Carbon\Carbon;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonViewPresenterTest extends TestCase
{
    private SermonExposurePolicy&MockInterface $exposurePolicy;

    private SermonStorageService&MockInterface $storageService;

    private SermonTranscriptReader&MockInterface $transcriptReader;

    private SermonViewPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exposurePolicy = Mockery::mock(SermonExposurePolicy::class);
        $this->storageService = Mockery::mock(SermonStorageService::class);
        $this->transcriptReader = Mockery::mock(SermonTranscriptReader::class);

        $this->presenter = new SermonViewPresenter(
            $this->exposurePolicy,
            $this->storageService,
            $this->transcriptReader
        );
    }

    #[Test]
    public function it_formats_duration_as_human_readable_string(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 2705]); // 45m (floored)

        $this->assertSame('45m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function it_formats_duration_as_iso8601_string(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 2705]);

        $this->assertSame('PT45M5S', $this->presenter->durationIso8601($sermon));
    }

    #[Test]
    public function it_returns_formatted_dates(): void
    {
        $sermon = Sermon::factory()->make(['date' => Carbon::parse('2024-03-10')]);

        $dates = $this->presenter->formattedDates($sermon);

        $this->assertSame('March 10, 2024', $dates['human']);
        $this->assertSame('2024-03-10', $dates['iso']);
        $this->assertSame('10 March 2024', $dates['short']);
    }

    #[Test]
    public function it_returns_human_date(): void
    {
        $sermon = Sermon::factory()->make(['date' => Carbon::parse('2024-03-10')]);

        $this->assertSame('March 10, 2024', $this->presenter->humanDate($sermon));
    }

    #[Test]
    public function it_resolves_preacher_name_from_profile_if_loaded(): void
    {
        $preacher = Preacher::factory()->make(['name' => 'John Profile']);
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame('John Profile', $this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_resolves_preacher_name_from_legacy_column_if_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);

        $this->assertSame('Legacy Name', $this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_resolves_preacher_image_url_from_profile_if_loaded(): void
    {
        $preacher = Preacher::factory()->make(['image_path' => 'preachers/john.webp']);
        $sermon = Sermon::factory()->make();
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame($preacher->profile_image_url, $this->presenter->preacherImageUrl($sermon));
    }

    #[Test]
    public function it_returns_null_preacher_image_url_if_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make();

        $this->assertNull($this->presenter->preacherImageUrl($sermon));
    }

    #[Test]
    public function it_returns_preacher_url_from_profile_slug_if_loaded(): void
    {
        $preacher = Preacher::factory()->make(['slug' => 'john-profile']);
        $sermon = Sermon::factory()->make();
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame(route('sermons.preacher', ['preacher' => 'john-profile']), $this->presenter->preacherUrl($sermon));
    }

    #[Test]
    public function it_returns_preacher_url_from_legacy_name_if_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);

        $this->assertSame(route('sermons.preacher', ['preacher' => 'legacy-name']), $this->presenter->preacherUrl($sermon));
    }

    #[Test]
    public function it_resolves_display_reference_from_passage_if_loaded(): void
    {
        $passage = ScripturePassage::factory()->make(['display_reference' => 'John 3:16']);
        $sermon = Sermon::factory()->make(['reference' => 'Legacy Ref']);
        $sermon->setRelation('scripturePassage', $passage);

        $this->assertSame('John 3:16', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_resolves_display_reference_from_legacy_column_if_passage_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['reference' => 'Legacy Ref']);

        $this->assertSame('Legacy Ref', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_returns_service_label(): void
    {
        $sermon = Sermon::factory()->make(['service' => SermonService::Morning]);

        $this->assertSame('Morning', $this->presenter->serviceLabel($sermon));
    }

    #[Test]
    public function it_returns_series_url(): void
    {
        $sermon = Sermon::factory()->make(['series' => 'Life Of David']);

        $this->assertSame(route('sermons.series.show', ['series' => 'life-of-david']), $this->presenter->seriesUrl($sermon));
    }

    #[Test]
    public function it_returns_audio_url_when_available(): void
    {
        $sermon = Sermon::factory()->make(['audio_file_path' => 'sermons/audio.mp3']);
        $this->storageService->shouldReceive('getAudioDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/audio.mp3');

        $this->assertSame('https://cdn.example.com/audio.mp3', $this->presenter->audioUrl($sermon));
    }

    #[Test]
    public function it_returns_video_url_when_exposed(): void
    {
        $sermon = Sermon::factory()->make();
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->with($sermon)->andReturn(true);
        $this->storageService->shouldReceive('getVideoDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/video.mp4');

        $this->assertSame('https://cdn.example.com/video.mp4', $this->presenter->videoUrl($sermon));
    }

    #[Test]
    public function it_returns_null_video_url_when_not_exposed(): void
    {
        $sermon = Sermon::factory()->make();
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->with($sermon)->andReturn(false);

        $this->assertNull($this->presenter->videoUrl($sermon));
    }

    #[Test]
    public function it_returns_thumbnail_url_when_exposed(): void
    {
        $sermon = Sermon::factory()->make([
            'video_file_path' => 'video.mp4',
            'thumbnail_file_path' => 'thumb.webp',
        ]);
        $this->exposurePolicy->shouldReceive('shouldExposeThumbnail')->with($sermon)->andReturn(true);
        $this->storageService->shouldReceive('getThumbnailDeliveryUrl')->with($sermon)->andReturn('https://cdn.example.com/thumb.webp');

        $this->assertSame('https://cdn.example.com/thumb.webp', $this->presenter->thumbnailUrl($sermon));
    }

    #[Test]
    public function it_generates_meta_description(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'Test Sermon',
            'preacher' => 'John Smith',
            'date' => Carbon::parse('2024-03-10'),
            'reference' => 'John 3:16',
            'service' => SermonService::Morning,
            'series' => null, // Keep it short so summary fits
            'show_summary' => true,
            'summary' => 'This is a summary.',
        ]);
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->andReturn(false);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString('Test Sermon', $description);
        $this->assertStringContainsString('John Smith', $description);
        $this->assertStringContainsString('March 10, 2024', $description);
        $this->assertStringContainsString('John 3:16', $description);
        $this->assertStringContainsString('This is a summary.', $description);
    }

    #[Test]
    public function it_memoizes_results(): void
    {
        $sermon = Sermon::factory()->make([
            'id' => 123,
            'updated_at' => now(),
            'audio_file_path' => 'original.mp3',
        ]);

        $this->storageService->shouldReceive('getAudioDeliveryUrl')->once()->with($sermon)->andReturn('https://cdn.example.com/url1');

        // First call computes and should call storage service
        $url1 = $this->presenter->audioUrl($sermon);

        // Modify sermon - audioUrl is memoized by sermon ID + updated_at
        $sermon->audio_file_path = 'changed.mp3';

        // Second call should return memoized result without calling storage service again
        $url2 = $this->presenter->audioUrl($sermon);

        $this->assertSame($url1, $url2);
        $this->assertSame('https://cdn.example.com/url1', $url2);
    }

    #[Test]
    public function it_clears_internal_caches(): void
    {
        $sermon = Sermon::factory()->make([
            'id' => 456,
            'updated_at' => now(),
            'series' => 'Cache Clear Test',
        ]);

        $url1 = $this->presenter->seriesUrl($sermon);

        $this->presenter->clearInternalCaches();

        $sermon->series = 'New Series';
        $url2 = $this->presenter->seriesUrl($sermon);

        $this->assertNotSame($url1, $url2);
        $this->assertStringContainsString('new-series', $url2);
    }
}

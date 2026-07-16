<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Enums\SermonVideoQualityStatus;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Sitemap\SermonSitemapPresenter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Sitemap\Tags\Url;
use Tests\TestCase;

class SermonSitemapPresenterTest extends TestCase
{
    private SermonSitemapPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new SermonSitemapPresenter(app(SermonViewPresenter::class));
    }

    #[Test]
    public function sermon_with_video_and_thumbnail_produces_video_and_image_entries(): void
    {
        Storage::fake('public');
        Storage::fake('sermons');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'title' => 'Test',
            'summary' => 'A test summary.',
            'preacher' => 'A B',
            'reference' => 'John 3',
            'series' => null,
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'duration' => 1800,
            'date' => '2026-02-15',
            'slug' => 'presenter-test',
        ]);

        $tag = $this->presenter->toSitemapTag($sermon);

        $this->assertInstanceOf(Url::class, $tag);
        $this->assertCount(1, $tag->videos);
        $this->assertCount(1, $tag->images);
        $this->assertEquals('Test', $tag->videos[0]->title);
        $this->assertStringContainsString('Test', $tag->videos[0]->description);
        $this->assertStringContainsString('A test summary.', $tag->videos[0]->description);
        $this->assertEquals(1800, $tag->videos[0]->options['duration']);
        $this->assertEquals($sermon->date->toIso8601String(), $tag->videos[0]->options['publication_date']);
    }

    #[Test]
    public function sermon_without_thumbnail_produces_no_video_entry(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => null,
            'date' => '2026-02-15',
            'slug' => 'no-thumb',
        ]);

        $tag = $this->presenter->toSitemapTag($sermon);

        $this->assertCount(0, $tag->videos);
    }

    #[Test]
    public function rejected_sermon_video_is_omitted_from_video_sitemap_entries(): void
    {
        Storage::fake('public');
        Storage::fake('sermons');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => 'thumbnails/fallback.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/fallback.jpg'],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'date' => '2026-02-15',
            'slug' => 'rejected-video',
        ]);

        $tag = $this->presenter->toSitemapTag($sermon);

        $this->assertCount(0, $tag->videos);
        $this->assertCount(1, $tag->images);
    }

    #[Test]
    public function sermon_without_summary_uses_presenter_meta_description_for_video_description(): void
    {
        Storage::fake('public');
        Storage::fake('sermons');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'title' => 'No Summary Sermon',
            'summary' => null,
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'date' => '2026-02-15',
            'slug' => 'no-summary',
        ]);

        $tag = $this->presenter->toSitemapTag($sermon);

        $this->assertCount(1, $tag->videos);
        $this->assertStringContainsString('No Summary Sermon', $tag->videos[0]->description);
        $this->assertEquals($sermon->date->toIso8601String(), $tag->videos[0]->options['publication_date']);
    }

    #[Test]
    public function it_builds_correct_url_path(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'slug' => 'test-sermon',
            'date' => '2025-06-15',
            'video_file_path' => null,
            'thumbnail_file_path' => null,
        ]);

        $tag = $this->presenter->toSitemapTag($sermon);

        $this->assertStringContainsString('/christ/sermons/2025/06/test-sermon', $tag->url);
    }
}

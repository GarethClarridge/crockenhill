<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sermon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Sitemap\Tags\Url;
use Tests\TestCase;

class SermonSitemapMediaTest extends TestCase
{
    #[Test]
    public function it_includes_video_and_image_metadata_in_sitemap_tag(): void
    {
        // Setup disks
        Storage::fake('public');
        Storage::fake('sermons');

        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        // Create a sermon with video and thumbnail
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'title' => 'Sitemap Media Test Sermon',
            'summary' => 'This is a test summary for the sitemap.',
            'video_file_path' => 'videos/test-sermon.mp4',
            'thumbnail_file_path' => 'thumbnails/test-sermon.jpg',
            'duration' => 1800,
            'date' => '2026-02-15',
            'slug' => 'test-sermon',
        ]);

        // hasThumbnail() now trusts the database column, so no physical file is needed

        // Generate the sitemap tag
        $tag = $sermon->toSitemapTag();

        $this->assertInstanceOf(Url::class, $tag);

        // Verify video metadata
        $this->assertCount(1, $tag->videos);
        $video = $tag->videos[0];
        $this->assertEquals('Sitemap Media Test Sermon', $video->title);
        $this->assertStringContainsString('This is a test summary for the sitemap.', $video->description);
        $this->assertEquals(1800, $video->options['duration']);
        $this->assertStringContainsString('videos/test-sermon.mp4', $video->contentLoc);
        $this->assertStringContainsString('thumbnails/test-sermon.jpg', $video->thumbnailLoc);

        // Verify image metadata
        $this->assertCount(1, $tag->images);
        $image = $tag->images[0];
        $this->assertEquals('Sitemap Media Test Sermon', $image->title);
        $this->assertStringContainsString('thumbnails/test-sermon.jpg', $image->url);
    }

    #[Test]
    public function it_excludes_video_metadata_when_video_is_missing(): void
    {
        Storage::fake('public');
        Config::set('thumbnail-generation.storage.disk', 'public');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'video_file_path' => null,
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'date' => '2026-02-15',
            'slug' => 'test-sermon',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertCount(0, $tag->videos);
        // Should still have the image if thumbnail exists
        $this->assertCount(1, $tag->images);
    }

    #[Test]
    public function it_excludes_video_metadata_when_thumbnail_is_missing(): void
    {
        /** @var Sermon $sermon */
        // Video in sitemap requires a thumbnail
        $sermon = Sermon::factory()->make([
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => null,
            'date' => '2026-02-15',
            'slug' => 'test-sermon',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertCount(0, $tag->videos);
    }

    #[Test]
    public function it_uses_title_as_fallback_for_video_description(): void
    {
        Storage::fake('public');
        Storage::fake('sermons');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'title' => 'Title Only Sermon',
            'summary' => null,
            'video_file_path' => 'videos/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'date' => '2026-02-15',
            'slug' => 'test-sermon',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertCount(1, $tag->videos);
        $this->assertStringContainsString('Title Only Sermon', $tag->videos[0]->description);
    }
}

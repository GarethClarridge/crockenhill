<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Sermon;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SermonStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('do_spaces');

        Config::set('media-processing.storage.sermon_disk', 'public');

        $this->service = new SermonStorageService;
    }

    #[Test]
    public function it_identifies_storage_pattern(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/2024/01/new-sermon.mp3',
            'filetype' => 'mp3',
        ]);

        $info = $this->service->getSermonFileInfo($sermon);

        $this->assertEquals('storage', $info['type']);
        $this->assertEquals('sermons/2024/01/new-sermon.mp3', $info['path']);
    }

    #[Test]
    public function it_generates_public_url_for_local_disk(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        $url = $this->service->getPublicUrl($sermon);

        $this->assertStringContainsString('/storage/sermons/test.mp3', $url);
        $this->assertStringContainsString('?v=', $url);
    }

    #[Test]
    public function it_generates_public_url_for_cdn(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/cdn-test.mp3',
        ]);

        $url = $this->service->getPublicUrl($sermon);

        $this->assertStringStartsWith('https://cdn.example.com/sermons/cdn-test.mp3?v=', $url);
    }

    #[Test]
    public function it_checks_file_existence_correctly(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/exists.mp3',
        ]);

        // File doesn't exist yet
        $this->assertFalse($this->service->fileExists($sermon));

        // Create file
        Storage::disk('public')->put('sermons/exists.mp3', 'content');

        $this->assertTrue($this->service->fileExists($sermon));
    }

    #[Test]
    public function it_gets_file_size_correctly(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/size.mp3',
        ]);

        Storage::disk('public')->put('sermons/size.mp3', '12345');

        $this->assertEquals(5, $this->service->getFileSize($sermon));
    }

    #[Test]
    public function it_returns_null_size_for_missing_file(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing.mp3',
        ]);

        $this->assertNull($this->service->getFileSize($sermon));
    }

    #[Test]
    public function it_does_not_cache_transient_metadata_failures_and_expires_successes(): void
    {
        Config::set('media-processing.storage.metadata_cache_ttl', 1);
        Cache::flush();

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/transient.mp3',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $lastModifiedCalls = 0;
        $sizeCalls = 0;

        $disk->shouldReceive('lastModified')
            ->times(3)
            ->andReturnUsing(static function () use (&$lastModifiedCalls): int {
                $lastModifiedCalls++;

                if ($lastModifiedCalls === 1) {
                    throw new RuntimeException('Temporary storage failure');
                }

                return $lastModifiedCalls === 2 ? 100 : 200;
            });
        $disk->shouldReceive('size')
            ->twice()
            ->andReturnUsing(static function () use (&$sizeCalls): int {
                $sizeCalls++;

                return $sizeCalls === 1 ? 5 : 7;
            });
        Storage::shouldReceive('disk')->with('public')->times(5)->andReturn($disk);

        $this->assertNull($this->service->getFileSize($sermon));
        $this->assertSame(5, $this->service->getFileSize($sermon));
        $this->assertSame(5, $this->service->getFileSize($sermon));

        $this->travel(2)->seconds();

        $this->assertSame(7, $this->service->getFileSize($sermon));
    }

    #[Test]
    public function it_discards_a_legacy_forever_cached_metadata_failure_and_rereads_storage(): void
    {
        Cache::flush();

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/poisoned.mp3',
        ]);

        // Before metadata caching gained a TTL, a storage failure was cached
        // forever as null values. Seed that exact legacy shape.
        $legacyKey = 'sermon_file_metadata_'.sha1(implode('|', [
            $sermon->id,
            $sermon->audio_file_path,
            $sermon->updated_at->getTimestamp(),
        ]));
        Cache::forever($legacyKey, ['last_modified' => null, 'size' => null]);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('lastModified')->once()->andReturn(100);
        $disk->shouldReceive('size')->once()->andReturn(42);
        Storage::shouldReceive('disk')->with('public')->twice()->andReturn($disk);

        $this->assertSame(42, $this->service->getFileSize($sermon));
        $this->assertSame(['last_modified' => 100, 'size' => 42], Cache::get($legacyKey));
    }

    #[Test]
    public function it_returns_video_url_for_sermon_with_video_path(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $url = $this->service->getVideoUrl($sermon);

        $this->assertStringContainsString('/storage/sermons/1/video.mp4', $url);
        $this->assertStringContainsString('?v=', $url);
    }

    /**
     * A reviewer's request carries no staging context, so the disk's identity —
     * not the presence of a running batch — has to be what refuses the URL.
     */
    #[Test]
    public function it_never_generates_a_public_url_for_the_historic_staging_disk(): void
    {
        Storage::fake('historic_staging');
        Config::set('media-processing.storage.historic_staging_disk', 'historic_staging');
        Config::set('media-processing.storage.sermon_disk', 'historic_staging');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be exposed through a public or CDN URL');

        $this->service->getVideoUrl($sermon);
    }

    #[Test]
    public function it_returns_video_url_from_cdn_when_configured(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $url = $this->service->getVideoUrl($sermon);

        $this->assertStringStartsWith('https://cdn.example.com/sermons/1/video.mp4?v=', $url);
    }

    #[Test]
    public function it_returns_null_video_url_when_no_video_path(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => null,
        ]);

        $this->assertNull($this->service->getVideoUrl($sermon));
    }

    #[Test]
    public function it_returns_thumbnail_url_for_sermon_with_thumbnail_path(): void
    {
        Storage::fake('thumb_disk');
        Config::set('thumbnail-generation.storage.disk', 'thumb_disk');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/sermon-1.jpg',
        ]);

        $url = $this->service->getThumbnailUrl($sermon);

        $this->assertStringContainsString('thumbnails/sermon-1.jpg', $url);
        $this->assertStringContainsString('?v=', $url);
    }

    #[Test]
    public function it_returns_thumbnail_url_from_cdn_when_configured(): void
    {
        Config::set('thumbnail-generation.storage.disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/sermon-1.jpg',
        ]);

        $url = $this->service->getThumbnailUrl($sermon);

        $this->assertStringStartsWith('https://cdn.example.com/sermons/thumbnails/sermon-1.jpg?v=', $url);
    }

    #[Test]
    public function it_returns_null_thumbnail_url_when_no_thumbnail_path(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => null,
        ]);

        $this->assertNull($this->service->getThumbnailUrl($sermon));
    }

    #[Test]
    public function it_returns_card_thumbnail_url_from_cdn_when_configured(): void
    {
        Config::set('thumbnail-generation.storage.disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');
        $this->service->clearInternalCaches();

        $sermon = Sermon::factory()->make([
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'sermons/thumbnails/sermon-card.jpg',
            ],
        ]);

        $url = $this->service->getCardThumbnailUrl($sermon);

        $this->assertStringStartsWith('https://cdn.example.com/sermons/thumbnails/sermon-card.jpg?v=', (string) $url);
    }

    #[Test]
    public function it_rejects_path_traversal_in_video_paths(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => '../secrets.mp4',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid video file path');

        $this->service->getVideoUrl($sermon);
    }

    #[Test]
    public function it_rejects_path_traversal_in_thumbnail_paths(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => '../secrets.jpg',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid thumbnail path');

        $this->service->getThumbnailUrl($sermon);
    }

    #[Test]
    public function it_rejects_path_traversal_in_card_thumbnail_paths(): void
    {
        $sermon = Sermon::factory()->make([
            'thumbnail_metadata' => [
                'card_thumbnail_path' => '../secrets.jpg',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid thumbnail path');

        $this->service->getCardThumbnailUrl($sermon);
    }

    #[Test]
    public function it_builds_admin_preview_urls_for_thumbnail_candidates(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'candidate-preview-sermon',
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 180.0,
                        'score' => 0.91,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        $url = $this->service->getAdminThumbnailCandidatePreviewUrl($sermon, 'candidate-1', 'overlay');

        $this->assertStringContainsString('/admin/sermons/candidate-preview-sermon/thumbnails/candidate-1/overlay', $url);
    }

    #[Test]
    public function it_returns_null_for_missing_candidate_overlay_preview(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'candidate-plain-only-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 180.0,
                        'score' => 0.91,
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        $this->assertNull($this->service->getAdminThumbnailCandidatePreviewUrl($sermon, 'candidate-1', 'overlay'));
        $this->assertStringContainsString(
            '/admin/sermons/candidate-plain-only-sermon/thumbnails/candidate-1/plain',
            (string) $this->service->getAdminThumbnailCandidatePreviewUrl($sermon, 'candidate-1', 'plain'),
        );
    }
}

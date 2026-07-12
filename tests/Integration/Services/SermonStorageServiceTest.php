<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Sermon;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
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
        Config::set('media-processing.storage.legacy_disk', 'public');

        $this->service = new SermonStorageService;
    }

    #[Test]
    public function it_identifies_legacy_storage_pattern(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'old-sermon',
            'filetype' => 'mp3',
        ]);

        $info = $this->service->getSermonFileInfo($sermon);

        $this->assertEquals('legacy', $info['type']);
        $this->assertEquals('legacy/sermons/old-sermon.mp3', $info['path']);
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
    public function it_returns_video_url_for_sermon_with_video_path(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $url = $this->service->getVideoUrl($sermon);

        $this->assertStringContainsString('/storage/sermons/1/video.mp4', $url);
        $this->assertStringContainsString('?v=', $url);
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
    public function it_returns_guarded_audio_delivery_url_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-audio-sermon',
            'audio_file_path' => 'private/sermons/audio/test.mp3',
        ]);

        $this->assertSame(
            route('sermons.audio', ['sermon' => $sermon->slug]),
            $this->service->getAudioDeliveryUrl($sermon),
        );
    }

    #[Test]
    public function it_returns_guarded_video_delivery_url_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-video-sermon',
            'video_file_path' => 'private/sermons/video/test.mp4',
        ]);

        $this->assertSame(
            route('sermons.video', ['sermon' => $sermon->slug]),
            $this->service->getVideoDeliveryUrl($sermon),
        );
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
    public function it_returns_guarded_thumbnail_delivery_url_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-thumbnail-sermon',
            'thumbnail_file_path' => 'private/thumbnails/test.jpg',
        ]);

        $this->assertSame(
            route('sermons.thumbnail', ['sermon' => $sermon->slug]),
            $this->service->getThumbnailDeliveryUrl($sermon),
        );
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
    public function it_returns_guarded_card_thumbnail_delivery_url_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-card-sermon',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'private/thumbnails/card.jpg',
            ],
        ]);

        $this->assertSame(
            route('sermons.thumbnail.card', ['sermon' => $sermon->slug]),
            $this->service->getCardThumbnailDeliveryUrl($sermon),
        );
    }

    #[Test]
    public function it_rejects_generating_direct_public_audio_urls_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/audio/test.mp3',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Private audio assets must be served through guarded sermon asset routes.');

        $this->service->getPublicUrl($sermon);
    }

    #[Test]
    public function it_rejects_generating_direct_public_video_urls_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'private/sermons/video/test.mp4',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Private video assets must be served through guarded sermon asset routes.');

        $this->service->getVideoUrl($sermon);
    }

    #[Test]
    public function it_rejects_generating_direct_public_thumbnail_urls_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'private/thumbnails/test.jpg',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Private thumbnail assets must be served through guarded sermon asset routes.');

        $this->service->getThumbnailUrl($sermon);
    }

    #[Test]
    public function it_returns_guarded_plain_thumbnail_delivery_url_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'private-plain-thumbnail-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbnails/plain.jpg',
            ],
        ]);

        $this->assertSame(
            route('sermons.thumbnail', ['sermon' => $sermon->slug]),
            $this->service->getPlainThumbnailDeliveryUrl($sermon),
        );
    }

    #[Test]
    public function it_rejects_generating_direct_public_plain_thumbnail_urls_for_private_assets(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbnails/plain.jpg',
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Private plain thumbnail assets must be served through guarded sermon asset routes.');

        $this->service->getPlainThumbnailUrl($sermon);
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

    #[Test]
    public function it_calculates_storage_stats_correctly(): void
    {
        // Clear any existing sermons
        Sermon::query()->delete();

        // Create a mix of sermons with different patterns and disks
        Sermon::factory()->create([
            'audio_file_path' => 'legacy-sermon',
            'filetype' => 'mp3',
        ]);
        Storage::disk('public')->put('legacy/sermons/legacy-sermon.mp3', 'abc'); // 3 bytes

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/new-sermon.mp3',
            'filetype' => 'mp3',
        ]);
        Storage::disk('public')->put('sermons/new-sermon.mp3', 'defgh'); // 5 bytes

        Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing.mp3',
        ]);

        $stats = $this->service->getStorageStats();

        $this->assertEquals(3, $stats['total_sermons']);
        $this->assertEquals(1, $stats['patterns']['legacy']);
        $this->assertEquals(2, $stats['patterns']['storage']);
        $this->assertEquals(8, $stats['total_size']);
        $this->assertEquals(1, $stats['missing_files']);

        $this->assertArrayHasKey('public', $stats['disks']);
        $this->assertEquals(3, $stats['disks']['public']['count']);
        $this->assertEquals(8, $stats['disks']['public']['size']);
        $this->assertEquals(1, $stats['disks']['public']['missing']);
    }
}

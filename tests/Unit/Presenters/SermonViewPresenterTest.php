<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    private SermonViewPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.transcript_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $this->presenter = app(SermonViewPresenter::class);
    }

    #[Test]
    public function it_presents_explicit_media_and_link_data(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Test Preacher',
            'slug' => 'test-preacher',
        ]);

        Storage::disk('public')->put('sermons/test.mp3', 'audio');
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/test.jpg', 'thumb');
        Storage::disk('public')->put('transcripts/test.md', 'Transcript body');

        $sermon = Sermon::factory()->create([
            'slug' => 'presented-sermon',
            'date' => '2026-02-15',
            'preacher' => 'Test Preacher',
            'preacher_id' => $preacher->id,
            'audio_file_path' => 'sermons/test.mp3',
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/test.jpg'],
            'transcript_file_path' => 'transcripts/test.md',
        ]);

        $sermon->load('preacherProfile');

        $presented = $this->presenter->present($sermon);

        $this->assertStringContainsString('/storage/sermons/test.mp3', $presented['audio_url'] ?? '');
        $this->assertStringContainsString('?v=', $presented['audio_url'] ?? '');
        $this->assertSame('http://localhost/christ/sermons/2026/02/presented-sermon', $presented['canonical_url']);
        $this->assertStringContainsString('/storage/thumbnails/test.jpg', $presented['card_thumbnail_url'] ?? '');
        $this->assertSame('/christ/sermons/preachers/test-preacher', $presented['preacher_url']);
        $this->assertSame('http://localhost/christ/sermons/presented-sermon', $presented['public_url']);
        $this->assertStringContainsString('/storage/thumbnails/test.jpg', $presented['thumbnail_url'] ?? '');
        $this->assertStringContainsString('?v=', $presented['thumbnail_url'] ?? '');
        $this->assertSame('Transcript body', $presented['transcript']);
        $this->assertStringContainsString('/storage/sermons/test.mp4', $presented['video_url'] ?? '');
    }

    #[Test]
    public function it_returns_null_optional_media_and_fallback_preacher_url_when_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Doe',
            'preacher_id' => null,
            'audio_file_path' => '',
            'video_file_path' => null,
            'thumbnail_file_path' => null,
            'transcript_file_path' => null,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['audio_url']);
        $this->assertNull($presented['card_thumbnail_url']);
        $this->assertSame('/christ/sermons/preachers/john-doe', $presented['preacher_url']);
        $this->assertNull($presented['thumbnail_url']);
        $this->assertNull($presented['transcript']);
        $this->assertNull($presented['video_url']);
    }

    #[Test]
    public function it_hides_rejected_video_urls_and_video_generated_thumbnails(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/test.jpg', 'thumb');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/test.jpg',
                'video_duration' => 1800.0,
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 300.0,
                        'score' => 0.9,
                        'plain_path' => 'thumbnails/test.jpg',
                    ],
                ],
            ],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['video_url']);
        $this->assertNull($presented['thumbnail_url']);
        $this->assertNull($presented['card_thumbnail_url']);
    }

    #[Test]
    public function it_keeps_non_video_generated_thumbnails_for_rejected_videos(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');
        Storage::disk('public')->put('thumbnails/fallback.jpg', 'thumb');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'thumbnail_file_path' => 'thumbnails/fallback.jpg',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/fallback.jpg'],
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertNull($presented['video_url']);
        $this->assertStringContainsString('/storage/thumbnails/fallback.jpg', $presented['thumbnail_url'] ?? '');
        $this->assertStringContainsString('/storage/thumbnails/fallback.jpg', $presented['card_thumbnail_url'] ?? '');
    }

    #[Test]
    public function it_allows_force_show_to_expose_a_rejected_video(): void
    {
        Storage::disk('public')->put('sermons/test.mp4', 'video');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/test.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceShow,
        ]);

        $presented = $this->presenter->present($sermon);

        $this->assertStringContainsString('/storage/sermons/test.mp4', $presented['video_url'] ?? '');
    }
}

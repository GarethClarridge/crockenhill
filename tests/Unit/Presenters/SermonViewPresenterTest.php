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
        $this->assertSame('http://localhost/christ/sermons/preachers/test-preacher', $presented['preacher_url']);
        $this->assertSame('http://localhost/christ/sermons/presented-sermon', $presented['public_url']);
        $this->assertStringContainsString('/storage/thumbnails/test.jpg', $presented['thumbnail_url'] ?? '');
        $this->assertStringContainsString('?v=', $presented['thumbnail_url'] ?? '');
        $this->assertSame('Transcript body', $presented['transcript']);
        $this->assertStringContainsString('/storage/sermons/test.mp4', $presented['video_url'] ?? '');
    }

    #[Test]
    public function it_exposes_plain_thumbnail_url_separately_from_card_thumbnail_url(): void
    {
        Storage::disk('public')->put('thumbnails/plain.jpg', 'plain');
        Storage::disk('public')->put('thumbnails/card.jpg', 'card');

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/overlay.jpg',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/plain.jpg',
                'card_thumbnail_path' => 'thumbnails/card.jpg',
            ],
        ]);

        $this->assertStringContainsString('/storage/thumbnails/plain.jpg', $this->presenter->plainThumbnailUrl($sermon) ?? '');
        $this->assertStringContainsString('/storage/thumbnails/card.jpg', $this->presenter->cardThumbnailUrl($sermon) ?? '');
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
        $this->assertSame('http://localhost/christ/sermons/preachers/john-doe', $presented['preacher_url']);
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

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_null(): void
    {
        $sermon = Sermon::factory()->make(['duration' => null]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_zero(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 0]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_returns_null_when_duration_is_negative(): void
    {
        $sermon = Sermon::factory()->make(['duration' => -60]);

        $this->assertNull($this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_formats_minutes_only(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 1800]); // 30 minutes

        $this->assertSame('30m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_formats_hours_and_minutes(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 5400]); // 1h 30m

        $this->assertSame('1h 30m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_handles_exactly_one_hour(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 3600]); // 1h 0m

        $this->assertSame('1h 0m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function formatted_duration_handles_sub_minute_duration(): void
    {
        $sermon = Sermon::factory()->make(['duration' => 45]); // 0m 45s → 0m

        $this->assertSame('0m', $this->presenter->formattedDuration($sermon));
    }

    #[Test]
    public function meta_description_returns_explicit_attribute_when_set(): void
    {
        $sermon = Sermon::factory()->make([
            'meta_description' => 'Custom meta description.',
        ]);

        $this->assertSame('Custom meta description.', $this->presenter->metaDescription($sermon));
    }

    #[Test]
    public function meta_description_generates_default_from_title_preacher_and_date(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString("Listen to 'The Prodigal Son' by John Smith", $description);
        $this->assertStringContainsString('preached on March 10, 2024', $description);
    }

    #[Test]
    public function meta_description_includes_scripture_reference_and_series(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
            'reference' => 'Luke 15:11-32',
            'series' => 'Parables of Jesus',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString(' - Luke 15:11-32', $description);
        $this->assertStringContainsString('(Part of the Parables of Jesus series)', $description);
    }

    #[Test]
    public function meta_description_includes_summary_when_enabled_and_strips_tags(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
            'reference' => null,
            'series' => null,
            'show_summary' => true,
            'summary' => '<p>This is a <strong>great</strong> sermon summary.</p>',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringContainsString('This is a great sermon summary.', $description);
        $this->assertStringNotContainsString('<p>', $description);
    }

    #[Test]
    public function meta_description_ignores_summary_when_disabled(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
            'reference' => null,
            'series' => null,
            'show_summary' => false,
            'summary' => 'This summary should be ignored.',
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertStringNotContainsString('This summary should be ignored.', $description);
    }

    #[Test]
    public function meta_description_enforces_limit_with_truncation(): void
    {
        $longTitle = str_repeat('Very Long Sermon Title ', 10);
        $sermon = Sermon::factory()->make([
            'title' => $longTitle,
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
            'reference' => null,
            'series' => null,
        ]);

        $description = $this->presenter->metaDescription($sermon);

        // Str::limit(s, 155) appends '...' making it 158 chars if limit is reached.
        $this->assertLessThanOrEqual(158, strlen($description));
        $this->assertStringEndsWith('...', $description);
    }

    #[Test]
    public function meta_description_truncates_summary_to_fit(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'The Prodigal Son',
            'preacher' => 'John Smith',
            'date' => '2024-03-10',
            'reference' => null,
            'series' => null,
            'show_summary' => true,
            'summary' => str_repeat('This is a very long summary that should definitely be truncated to ensure the total length remains within expected limits. ', 10),
        ]);

        $description = $this->presenter->metaDescription($sermon);

        $this->assertLessThanOrEqual(158, strlen($description));
        $this->assertStringContainsString("Listen to 'The Prodigal Son' by John Smith", $description);
        $this->assertStringEndsWith('...', $description);
    }
}

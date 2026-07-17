<?php

declare(strict_types=1);

namespace Tests\Integration\Data;

use App\Data\ApiBiblePassageResult;
use App\Data\LivestreamProcessingResult;
use App\Data\LivestreamSegment;
use App\Data\PodcastFeedItemReadModel;
use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Models\SpeakerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingResultsDataTest extends TestCase
{
    use RefreshDatabase;

    // ── ApiBiblePassageResult ─────────────────────────────────────────────────

    #[Test]
    public function api_bible_passage_result_stores_all_properties(): void
    {
        $result = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world...</p>',
            copyright: '© ESV Bible',
            fumsToken: 'token-abc-123',
        );

        $this->assertSame('JHN.3.16', $result->passageId);
        $this->assertSame('John 3:16', $result->displayReference);
        $this->assertSame('<p>For God so loved the world...</p>', $result->htmlContent);
        $this->assertSame('© ESV Bible', $result->copyright);
        $this->assertSame('token-abc-123', $result->fumsToken);
    }

    #[Test]
    public function api_bible_passage_result_supports_null_fums_token(): void
    {
        $result = new ApiBiblePassageResult(
            passageId: 'ROM.8.1',
            displayReference: 'Romans 8:1',
            htmlContent: '<p>There is therefore now no condemnation...</p>',
            copyright: '© ESV',
            fumsToken: null,
        );

        $this->assertNull($result->fumsToken);
    }

    // ── PodcastFeedItemReadModel ───────────────────────────────────────────────

    #[Test]
    public function podcast_feed_item_stores_all_properties(): void
    {
        $item = new PodcastFeedItemReadModel(
            canonicalUrl: 'https://example.com/sermons/42',
            enclosureLength: 10485760,
            enclosureUrl: 'https://cdn.example.com/sermons/42.mp3',
            episodeImageUrl: 'https://cdn.example.com/sermons/42.jpg',
            itunesDuration: '00:45:30',
            podcastSummary: 'A sermon about grace.',
            preacherName: 'Jane Smith',
            publishedAt: '2024-03-10T10:30:00Z',
            sermonId: 42,
            title: 'Amazing Grace',
            transcriptUrl: 'https://example.com/sermons/42/transcript',
        );

        $this->assertSame('https://example.com/sermons/42', $item->canonicalUrl);
        $this->assertSame(10485760, $item->enclosureLength);
        $this->assertSame('https://cdn.example.com/sermons/42.mp3', $item->enclosureUrl);
        $this->assertSame('https://cdn.example.com/sermons/42.jpg', $item->episodeImageUrl);
        $this->assertSame('00:45:30', $item->itunesDuration);
        $this->assertSame('A sermon about grace.', $item->podcastSummary);
        $this->assertSame('Jane Smith', $item->preacherName);
        $this->assertSame('2024-03-10T10:30:00Z', $item->publishedAt);
        $this->assertSame(42, $item->sermonId);
        $this->assertSame('Amazing Grace', $item->title);
        $this->assertSame('https://example.com/sermons/42/transcript', $item->transcriptUrl);
    }

    #[Test]
    public function podcast_feed_item_supports_null_optional_fields(): void
    {
        $item = new PodcastFeedItemReadModel(
            canonicalUrl: 'https://example.com/sermons/1',
            enclosureLength: 1024,
            enclosureUrl: 'https://cdn.example.com/1.mp3',
            episodeImageUrl: null,
            itunesDuration: '00:30:00',
            podcastSummary: 'Summary',
            preacherName: null,
            publishedAt: '2024-01-01T10:00:00Z',
            sermonId: 1,
            title: 'Sermon Title',
            transcriptUrl: null,
        );

        $this->assertNull($item->episodeImageUrl);
        $this->assertNull($item->preacherName);
        $this->assertNull($item->transcriptUrl);
    }

    // ── SpeakerEmbeddingResult ────────────────────────────────────────────────

    #[Test]
    public function speaker_embedding_result_success_factory(): void
    {
        $result = SpeakerEmbeddingResult::success([0.1, 0.2, 0.3], 2.5);

        $this->assertTrue($result->success);
        $this->assertSame([0.1, 0.2, 0.3], $result->embedding);
        $this->assertSame(2.5, $result->durationUsed);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function speaker_embedding_result_failed_factory(): void
    {
        $result = SpeakerEmbeddingResult::failed('Audio too short');

        $this->assertFalse($result->success);
        $this->assertNull($result->embedding);
        $this->assertNull($result->durationUsed);
        $this->assertSame('Audio too short', $result->errorMessage);
    }

    // ── SpeakerMatchResult ────────────────────────────────────────────────────

    #[Test]
    public function speaker_match_result_matched_factory(): void
    {
        $profile = SpeakerProfile::factory()->create();

        $result = SpeakerMatchResult::matched(
            profile: $profile,
            topScore: 0.92,
            secondScore: 0.71,
            allScores: [$profile->id => 0.92],
        );

        $this->assertTrue($result->matched);
        $this->assertFalse($result->errored);
        $this->assertSame($profile->id, $result->matchedProfileId);
        $this->assertSame(0.92, $result->topScore);
        $this->assertSame(0.71, $result->secondScore);
        $this->assertEqualsWithDelta(0.21, $result->margin, 0.001);
    }

    #[Test]
    public function speaker_match_result_no_match_factory(): void
    {
        $result = SpeakerMatchResult::noMatch(topScore: 0.4, reason: 'Below threshold');

        $this->assertFalse($result->matched);
        $this->assertFalse($result->errored);
        $this->assertSame(0.4, $result->topScore);
        $this->assertSame('Below threshold', $result->reason);
    }

    #[Test]
    public function speaker_match_result_no_profiles_factory(): void
    {
        $result = SpeakerMatchResult::noProfiles();

        $this->assertFalse($result->matched);
        $this->assertSame('No active speaker profiles', $result->reason);
    }

    #[Test]
    public function speaker_match_result_error_factory(): void
    {
        $result = SpeakerMatchResult::error('Service unavailable');

        $this->assertFalse($result->matched);
        $this->assertTrue($result->errored);
        $this->assertSame('Service unavailable', $result->reason);
    }

    #[Test]
    public function speaker_match_result_to_log_array_has_expected_keys(): void
    {
        $result = SpeakerMatchResult::noMatch();

        $log = $result->toLogArray();

        foreach (['matched', 'errored', 'matched_profile_id', 'matched_preacher_id', 'matched_preacher_name', 'top_score', 'second_score', 'margin', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $log, "Missing key: {$key}");
        }
    }

    // ── LivestreamProcessingResult ────────────────────────────────────────────

    #[Test]
    public function livestream_result_status_helpers(): void
    {
        $make = fn (string $status) => new LivestreamProcessingResult(
            processingId: 'run-1',
            status: $status,
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
        );

        $this->assertTrue($make('completed')->isCompleted());
        $this->assertTrue($make('failed')->isFailed());
        $this->assertTrue($make('pending')->isPending());
        $this->assertFalse($make('completed')->isProcessing());

        foreach (['processing', 'segmentation_complete', 'extraction_complete', 'sermon_submitted'] as $status) {
            $this->assertTrue($make($status)->isProcessing(), "Expected isProcessing() for status: {$status}");
        }
    }

    #[Test]
    public function livestream_result_has_sermon_and_segments(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'completed',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
            sermonId: 99,
            segments: [
                new LivestreamSegment(0.0, 600.0, 600.0, 'speech', 0.1, 0.3),
            ],
        );

        $this->assertTrue($result->hasSermon());
        $this->assertTrue($result->hasSegments());
    }

    #[Test]
    public function livestream_result_has_no_sermon_or_segments_by_default(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'pending',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
        );

        $this->assertFalse($result->hasSermon());
        $this->assertFalse($result->hasSegments());
    }

    #[Test]
    public function livestream_result_file_size_formatted(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'completed',
            originalFilename: 'service.mp4',
            fileSize: 1048576, // 1 MB
            fileFormat: 'mp4',
        );

        $this->assertStringContainsString('MB', $result->getFileSizeFormatted());
    }

    #[Test]
    public function livestream_result_duration_formatted_with_hours(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'completed',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
            duration: 3661.0,
        );

        $this->assertSame('01:01:01', $result->getDurationFormatted());
    }

    #[Test]
    public function livestream_result_duration_formatted_without_hours(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'completed',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
            duration: 125.0,
        );

        $this->assertSame('02:05', $result->getDurationFormatted());
    }

    #[Test]
    public function livestream_result_duration_formatted_returns_null_when_no_duration(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'pending',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
        );

        $this->assertNull($result->getDurationFormatted());
    }

    #[Test]
    public function livestream_result_sermon_duration_formatted(): void
    {
        $result = new LivestreamProcessingResult(
            processingId: 'run-1',
            status: 'completed',
            originalFilename: 'service.mp4',
            fileSize: 1024,
            fileFormat: 'mp4',
            sermonStartTime: 300.0,
            sermonEndTime: 2100.0,
        );

        $this->assertSame('30m 0s', $result->getSermonDurationFormatted());
    }

    #[Test]
    public function livestream_result_progress_percentage(): void
    {
        $make = fn (string $status) => new LivestreamProcessingResult('id', $status, 'f.mp4', 1, 'mp4');

        $this->assertSame(0, $make('pending')->getProgressPercentage());
        $this->assertSame(25, $make('processing')->getProgressPercentage());
        $this->assertSame(50, $make('segmentation_complete')->getProgressPercentage());
        $this->assertSame(75, $make('extraction_complete')->getProgressPercentage());
        $this->assertSame(90, $make('sermon_submitted')->getProgressPercentage());
        $this->assertSame(100, $make('completed')->getProgressPercentage());
        $this->assertSame(0, $make('failed')->getProgressPercentage());
    }

    #[Test]
    public function livestream_result_status_display_name(): void
    {
        $make = fn (string $status) => new LivestreamProcessingResult('id', $status, 'f.mp4', 1, 'mp4');

        $this->assertSame('Completed', $make('completed')->getStatusDisplayName());
        $this->assertSame('Failed', $make('failed')->getStatusDisplayName());
        $this->assertSame('Segmentation Complete', $make('segmentation_complete')->getStatusDisplayName());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Services\DetectorEvaluation;

use App\Enums\ProcessingStatus;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use App\Services\DetectorEvaluation\SongPublicationReviewSignals;
use App\Services\DetectorEvaluation\VideoQualityVerdictSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoAndSongSignalAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rejection_resolves_to_the_dead_picture_detector_and_counts_as_held(): void
    {
        $run = $this->runWithSermon(SermonVideoQualityStatus::Rejected, 'mostly_black');

        $signals = app(VideoQualityVerdictSignals::class)->for($run);

        $this->assertIsArray($signals);
        $this->assertCount(1, $signals);
        $this->assertSame('video-dead-picture', $signals[0]->detectorId);
        $this->assertSame('mostly_black', $signals[0]->signal);
        $this->assertTrue($signals[0]->held);
        $this->assertNotNull($signals[0]->sermonId);
    }

    public function test_a_needs_review_verdict_is_reported_but_did_not_hold(): void
    {
        $run = $this->runWithSermon(SermonVideoQualityStatus::NeedsReview, 'partially_frozen');

        $signals = app(VideoQualityVerdictSignals::class)->for($run);

        $this->assertIsArray($signals);
        $this->assertCount(1, $signals);
        $this->assertFalse($signals[0]->held);
    }

    /**
     * The whole reason this surface is worth having: `approved` is a positive
     * record that the detector looked, not an inference from absence.
     */
    public function test_an_approved_sermon_is_explicit_detector_negative_evidence(): void
    {
        $run = $this->runWithSermon(SermonVideoQualityStatus::Approved, null);
        $adapter = app(VideoQualityVerdictSignals::class);

        $this->assertSame([], $adapter->for($run));
        $this->assertTrue($adapter->isDetectorNegative($run));
        $this->assertTrue($adapter->wasAssessed($run));
    }

    public function test_an_unassessed_sermon_is_unknown_not_sound(): void
    {
        $run = $this->runWithSermon(SermonVideoQualityStatus::Unassessed, null);
        $adapter = app(VideoQualityVerdictSignals::class);

        $this->assertNull($adapter->for($run));
        $this->assertFalse($adapter->wasAssessed($run));
        $this->assertFalse($adapter->isDetectorNegative($run));
    }

    /**
     * A forced-visible rejection must still read as a rejection. Scoring the
     * effective outcome would credit the detector for an operator's repair.
     */
    public function test_a_visibility_override_does_not_change_the_recorded_verdict(): void
    {
        $run = $this->runWithSermon(
            SermonVideoQualityStatus::Rejected,
            'frozen_frames',
            SermonVideoVisibilityOverride::ForceShow,
        );

        $signals = app(VideoQualityVerdictSignals::class)->for($run);

        $this->assertIsArray($signals);
        $this->assertTrue($signals[0]->held, 'The detector still rejected it; a person overrode the consequence.');
        $this->assertSame('force_show', $signals[0]->context['visibility_override']);
    }

    public function test_a_recorded_song_objection_resolves_to_its_detector(): void
    {
        $run = $this->processingRun();
        $section = $this->songSection($run, [
            SongPublicationBoundaryEvidenceService::METADATA_KEY => ['risks' => []],
            'song_publication_review' => [
                'reasons' => [['kind' => 'unresolved_multiple_songs', 'detail' => 'Two songs in one clip.']],
                'decided_at' => '2026-09-14T10:00:00+00:00',
            ],
        ], needsReview: true);

        $signals = app(SongPublicationReviewSignals::class)->forSection($run, $section);

        $this->assertIsArray($signals);
        $this->assertCount(1, $signals);
        $this->assertSame('song-unresolved-multiple-songs', $signals[0]->detectorId);
        $this->assertTrue($signals[0]->held);
        $this->assertSame('2026-09-14T10:00:00+00:00', $signals[0]->context['decided_at']);
    }

    /**
     * Banked boundary evidence with no review key is the discriminator that
     * separates "assessed and clear" from "nobody looked".
     */
    public function test_banked_evidence_without_a_review_is_assessed_and_clear(): void
    {
        $run = $this->processingRun();
        $section = $this->songSection($run, [
            SongPublicationBoundaryEvidenceService::METADATA_KEY => ['risks' => []],
        ], needsReview: false);

        $adapter = app(SongPublicationReviewSignals::class);

        $this->assertSame([], $adapter->forSection($run, $section));
        $this->assertTrue($adapter->isDetectorNegative($section));
    }

    public function test_a_section_with_no_banked_evidence_was_never_assessed(): void
    {
        $run = $this->processingRun();
        $section = $this->songSection($run, [], needsReview: false);

        $adapter = app(SongPublicationReviewSignals::class);

        $this->assertNull($adapter->forSection($run, $section));
        $this->assertFalse(
            $adapter->isDetectorNegative($section),
            'The 19 unavailable sections were deliberately left unwritten and must not read as clear.'
        );
    }

    private function processingRun(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Completed]);
    }

    private function runWithSermon(
        SermonVideoQualityStatus $status,
        ?string $reason,
        SermonVideoVisibilityOverride $override = SermonVideoVisibilityOverride::Default,
    ): MediaProcessingLog {
        $sermon = Sermon::factory()->create([
            'video_quality_status' => $status,
            'video_quality_reason' => $reason,
            'video_visibility_override' => $override,
        ]);

        return MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Completed,
            'sermon_id' => $sermon->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function songSection(MediaProcessingLog $run, array $metadata, bool $needsReview): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => $needsReview,
            'metadata' => ['confidence_level' => 'high'] + $metadata,
        ]);
    }
}

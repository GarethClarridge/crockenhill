<?php

declare(strict_types=1);

namespace Tests\Feature\Services\DetectorEvaluation;

use App\Data\SuspectTranscriptBlock;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\SectionReviewFlagSignals;
use App\Services\DetectorEvaluation\SuspectTranscriptBlockSignals;
use App\Support\RetiredSectionReviewFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_stored_review_flag_to_its_catalogued_detector(): void
    {
        $run = $this->processingRun();
        $this->section($run, [ServiceStructureValidator::FLAG_MACRO_SECTION], needsReview: true);

        $signals = app(SectionReviewFlagSignals::class)->for($run);

        $this->assertCount(1, $signals);
        $this->assertSame('structure-macro-section', $signals[0]->detectorId);
        $this->assertSame(ServiceStructureValidator::FLAG_MACRO_SECTION, $signals[0]->signal);
        $this->assertSame((int) $run->id, $signals[0]->runId);
        $this->assertTrue($signals[0]->held);
    }

    public function test_it_takes_held_from_the_section_rather_than_the_flag(): void
    {
        $run = $this->processingRun();
        $this->section($run, [ServiceStructureValidator::FLAG_LOW_CONFIDENCE], needsReview: false);

        $signals = app(SectionReviewFlagSignals::class)->for($run);

        $this->assertCount(1, $signals);
        $this->assertFalse(
            $signals[0]->held,
            'A flag demoted by SectionReviewFlagPolicy fired without holding, and has therefore contained nothing.'
        );
    }

    public function test_it_skips_retired_flags(): void
    {
        $run = $this->processingRun();
        $this->section($run, [RetiredSectionReviewFlags::ALL[0]], needsReview: true);

        $this->assertSame([], app(SectionReviewFlagSignals::class)->for($run));
    }

    /**
     * An unknown flag is carried through with a null detector id rather than
     * dropped, because it is precisely the coverage gap the harness exists to
     * report.
     */
    public function test_it_carries_an_uncatalogued_flag_through_unresolved(): void
    {
        $run = $this->processingRun();
        $this->section($run, ['some_flag_no_detector_claims'], needsReview: true);

        $signals = app(SectionReviewFlagSignals::class)->for($run);

        $this->assertCount(1, $signals);
        $this->assertNull($signals[0]->detectorId);
        $this->assertFalse($signals[0]->isCatalogued());
    }

    public function test_an_unscreened_run_is_unknown_not_clean(): void
    {
        $run = $this->processingRun();
        $adapter = app(SuspectTranscriptBlockSignals::class);

        $this->assertNull($adapter->for($run));
        $this->assertFalse($adapter->wasScreened($run));
        $this->assertFalse(
            $adapter->isDetectorNegative($run),
            'A run the screen never looked at must not count as a run the screen cleared.'
        );
    }

    public function test_a_screened_clear_run_is_detector_negative(): void
    {
        $run = $this->processingRun();
        $run->putServiceTranscriptPath('transcripts/run.json', [], []);
        $run->refresh();

        $adapter = app(SuspectTranscriptBlockSignals::class);

        $this->assertSame([], $adapter->for($run));
        $this->assertTrue($adapter->wasScreened($run));
        $this->assertTrue($adapter->isDetectorNegative($run));
    }

    public function test_it_resolves_a_recorded_suspect_block_to_its_detector(): void
    {
        $run = $this->processingRun();
        $run->putServiceTranscriptPath('transcripts/run.json', [], [
            (new SuspectTranscriptBlock(
                start: 120.0,
                end: 180.0,
                reason: SuspectTranscriptBlock::REASON_SPARSE_CADENCE,
                words: 12,
                wordsPerMinute: 12.0,
            ))->toArray(),
        ]);
        $run->refresh();

        $signals = app(SuspectTranscriptBlockSignals::class)->for($run);

        $this->assertIsArray($signals);
        $this->assertCount(1, $signals);
        $this->assertSame('transcript-sparse-cadence', $signals[0]->detectorId);
        $this->assertSame(120.0, $signals[0]->start);
        $this->assertSame(180.0, $signals[0]->end);
        $this->assertTrue($signals[0]->held);
        $this->assertFalse(app(SuspectTranscriptBlockSignals::class)->isDetectorNegative($run));
    }

    private function processingRun(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Completed,
        ]);
    }

    /**
     * @param  list<string>  $reviewFlags
     */
    private function section(MediaProcessingLog $run, array $reviewFlags, bool $needsReview): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => $needsReview,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
                'review_flags' => $reviewFlags,
            ],
        ]);
    }
}

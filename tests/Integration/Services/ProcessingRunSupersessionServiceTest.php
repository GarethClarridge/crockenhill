<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ProcessingRunSupersessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingRunSupersessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingRunSupersessionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProcessingRunSupersessionService::class);
    }

    #[Test]
    public function a_failed_run_with_more_confirmed_songs_wins_over_a_completed_run(): void
    {
        $churchService = $this->churchService();

        $weakRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($weakRun, [0.3, 0.3, 0.4]);

        $strongRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($strongRun, [0.9, 0.95, 0.98], songMatch: ServiceSectionSongMatchType::Confirmed);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue($result['winner']->is($strongRun));
        $this->assertSame([$weakRun->id], $result['superseded']);

        $this->assertNotNull($weakRun->fresh()->superseded_at);
        $this->assertSame($strongRun->id, $weakRun->fresh()->superseded_by_processing_log_id);
        $this->assertNull($strongRun->fresh()->superseded_at);
    }

    #[Test]
    public function a_failed_run_does_not_win_on_confidence_alone(): void
    {
        // Reproduces run 936 (service 544): re-detection merged a completed run's
        // fragments, so an old failed run with more high-confidence sections and no
        // more confirmed songs took the service away from it.
        $churchService = $this->churchService();

        $completedRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($completedRun, [0.97, 0.97]);

        $failedRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($failedRun, [0.9, 0.9, 0.9, 0.9]);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue($result['winner']->is($completedRun));
        $this->assertSame([$failedRun->id], $result['superseded']);
        $this->assertNull($completedRun->fresh()->superseded_at);
    }

    #[Test]
    public function a_run_being_re_detected_is_not_handed_over_to_a_failed_run_on_confidence_alone(): void
    {
        // Canary 4: re-detection re-opens run 936, and projection reconciles before the run
        // completes again, so a ranking on completion still gave the service to failed 934.
        $churchService = $this->churchService();

        $reDetectedRun = $this->processingRun($churchService, status: 'processing');
        $this->sections($reDetectedRun, [0.97, 0.97]);

        $failedRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($failedRun, [0.9, 0.9, 0.9, 0.9]);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue($result['winner']->is($reDetectedRun));
        $this->assertSame([$failedRun->id], $result['superseded']);
    }

    #[Test]
    public function the_run_with_transcript_confirmed_songs_wins_over_a_higher_confidence_run_without_them(): void
    {
        // Reproduces service 785: a failed run classified its sections with a
        // marginally higher self-assessed confidence but only *inferred* its
        // songs by projecting the plan, while a later completed run actually
        // matched the songs against the catalogue transcript (Confirmed).
        // Confirmed matches are grounded evidence and must outrank the softer
        // classification prior, so the completed run wins.
        $churchService = $this->churchService();

        $inferredRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($inferredRun, [0.9, 0.95, 0.98, 0.9], songMatch: ServiceSectionSongMatchType::Inferred);

        $confirmedRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($confirmedRun, [0.6, 0.7, 0.8], songMatch: ServiceSectionSongMatchType::Confirmed);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue(
            $result['winner']->is($confirmedRun),
            'The run whose songs were transcript-confirmed must win over one that only inferred them.'
        );
        $this->assertSame([$inferredRun->id], $result['superseded']);
        $this->assertNull($confirmedRun->fresh()->superseded_at);
        $this->assertNotNull($inferredRun->fresh()->superseded_at);
    }

    #[Test]
    public function a_completed_run_wins_the_tiebreak_over_a_failed_run_carrying_equal_evidence(): void
    {
        // Service 785's two surviving candidates confirmed the identical songs and
        // had equal high-confidence coverage; the only real difference was that one
        // completed and the other failed. Status ranks after confirmed songs, so it
        // never vetoes better song evidence — but here, all else equal, the run that
        // actually finished the pipeline is the record to keep.
        $churchService = $this->churchService();

        // The failed run has a marginally higher mean confidence, so without a
        // status tiebreak it would win once the hard evidence terms tie.
        $failedRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($failedRun, [0.95, 0.9], songMatch: ServiceSectionSongMatchType::Confirmed);

        $completedRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($completedRun, [0.9, 0.9], songMatch: ServiceSectionSongMatchType::Confirmed);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue(
            $result['winner']->is($completedRun),
            'With equal song evidence and coverage, the completed run must win the tiebreak.'
        );
        $this->assertSame([$failedRun->id], $result['superseded']);
    }

    #[Test]
    public function reconcile_clears_the_service_review_phantom_left_by_a_superseded_run(): void
    {
        // Reproduces service 790: a completed run left stale manual-review section
        // flags plus orphaned service-level triggers, then a stronger re-run
        // superseded it — but the service stayed flagged with nothing actionable.
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
            'import_metadata' => [
                'review_triggers' => ['ambiguous_sermon_detection', 'unmatched_song_sections', 'manual_review_sections'],
                'confidence_score' => 1,
            ],
        ]);

        // The losing "completed" run carries the stale manual-review flags.
        $weakRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($weakRun, [0.3, 0.3, 0.4, 0.4], needsManualReview: true);

        // The "failed" re-run confirmed its songs, so it wins, and is clean.
        $strongRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($strongRun, [0.9, 0.95, 0.98], songMatch: ServiceSectionSongMatchType::Confirmed);

        $result = $this->service->reconcile($churchService);

        $this->assertSame([$weakRun->id], $result['superseded']);

        $churchService->refresh();
        $this->assertFalse(
            $churchService->needs_review,
            'The phantom must clear once the flagged run is superseded.'
        );
        $this->assertArrayNotHasKey(
            'review_triggers',
            $churchService->import_metadata?->toArray() ?? [],
            'Orphaned triggers must be dropped, not just the allow-listed one.'
        );
    }

    #[Test]
    public function a_dry_run_does_not_recompute_service_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-06-21',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
            'import_metadata' => ['review_triggers' => ['manual_review_sections'], 'confidence_score' => 1],
        ]);

        $weakRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($weakRun, [0.3], needsManualReview: true);
        $strongRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($strongRun, [0.9, 0.95, 0.98]);

        $this->service->reconcile($churchService, execute: false);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review, 'A dry run must not touch service review state.');
        $this->assertSame(
            ['manual_review_sections'],
            $churchService->import_metadata?->toArray()['review_triggers'] ?? null,
        );
    }

    #[Test]
    public function a_single_run_is_never_superseded(): void
    {
        $churchService = $this->churchService();
        $run = $this->processingRun($churchService);
        $this->sections($run, [0.9]);

        $result = $this->service->reconcile($churchService);

        $this->assertSame([], $result['superseded']);
        $this->assertNull($run->fresh()->superseded_at);
    }

    #[Test]
    public function reconciliation_is_idempotent(): void
    {
        $churchService = $this->churchService();
        $weakRun = $this->processingRun($churchService);
        $this->sections($weakRun, [0.3]);
        $strongRun = $this->processingRun($churchService);
        $this->sections($strongRun, [0.9]);

        $this->service->reconcile($churchService);
        $result = $this->service->reconcile($churchService);

        $this->assertSame([$weakRun->id], $result['superseded']);
        $this->assertNull($strongRun->fresh()->superseded_at);
        $this->assertNotNull($weakRun->fresh()->superseded_at);
    }

    #[Test]
    public function a_dry_run_reports_without_persisting(): void
    {
        $churchService = $this->churchService();
        $weakRun = $this->processingRun($churchService);
        $this->sections($weakRun, [0.3]);
        $strongRun = $this->processingRun($churchService);
        $this->sections($strongRun, [0.9]);

        $result = $this->service->reconcile($churchService, execute: false);

        $this->assertSame([$weakRun->id], $result['superseded']);
        $this->assertNull($weakRun->fresh()->superseded_at);
    }

    private function churchService(): ChurchService
    {
        return ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning->value,
        ]);
    }

    private function processingRun(ChurchService $service, string $status = 'completed'): MediaProcessingLog
    {
        $factory = MediaProcessingLog::factory()->livestream();
        $factory = match ($status) {
            'failed' => $factory->failed(),
            'processing' => $factory->processing(),
            default => $factory->completed(),
        };

        return $factory->create([
            'church_service_id' => $service->id,
            'extracted_date' => $service->date,
            'extracted_service' => $service->service,
        ]);
    }

    /**
     * @param  list<float>  $confidences
     */
    private function sections(
        MediaProcessingLog $run,
        array $confidences,
        bool $needsManualReview = false,
        ?ServiceSectionSongMatchType $songMatch = null,
    ): void {
        foreach ($confidences as $index => $confidence) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $run->id,
                'section_type' => ServiceSectionType::Song,
                'status' => ServiceSectionStatus::Identified,
                'confidence' => $confidence,
                'section_order' => $index + 1,
                'needs_manual_review' => $needsManualReview,
                'song_match_type' => $songMatch,
            ]);
        }
    }
}

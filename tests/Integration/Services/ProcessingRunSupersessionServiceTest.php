<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
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
    public function the_higher_confidence_run_wins_even_when_it_failed(): void
    {
        $churchService = $this->churchService();

        // The "completed" run carries a weaker structure.
        $weakRun = $this->processingRun($churchService, status: 'completed');
        $this->sections($weakRun, [0.3, 0.3, 0.4]);

        // The "failed" re-run carries the stronger structure — it must still win.
        $strongRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($strongRun, [0.9, 0.95, 0.98]);

        $result = $this->service->reconcile($churchService);

        $this->assertTrue($result['winner']->is($strongRun));
        $this->assertSame([$weakRun->id], $result['superseded']);

        $this->assertNotNull($weakRun->fresh()->superseded_at);
        $this->assertSame($strongRun->id, $weakRun->fresh()->superseded_by_processing_log_id);
        $this->assertNull($strongRun->fresh()->superseded_at);
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

        // The stronger "failed" re-run wins and is clean.
        $strongRun = $this->processingRun($churchService, status: 'failed');
        $this->sections($strongRun, [0.9, 0.95, 0.98]);

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
        $factory = $status === 'failed' ? $factory->failed() : $factory->completed();

        return $factory->create([
            'church_service_id' => $service->id,
            'extracted_date' => $service->date,
            'extracted_service' => $service->service,
        ]);
    }

    /**
     * @param  list<float>  $confidences
     */
    private function sections(MediaProcessingLog $run, array $confidences, bool $needsManualReview = false): void
    {
        foreach ($confidences as $index => $confidence) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $run->id,
                'section_type' => ServiceSectionType::Song,
                'status' => ServiceSectionStatus::Identified,
                'confidence' => $confidence,
                'section_order' => $index + 1,
                'needs_manual_review' => $needsManualReview,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Queries\ServiceReviewDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupersedeDuplicateRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dry_run_reports_but_changes_nothing(): void
    {
        [$service, $weakRun] = $this->serviceWithTwoRuns();

        $this->artisan('services:supersede-duplicate-runs')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNull($weakRun->fresh()->superseded_at);
    }

    #[Test]
    public function it_supersedes_the_weaker_duplicate_run_on_execute(): void
    {
        [$service, $weakRun, $strongRun] = $this->serviceWithTwoRuns();

        $this->artisan('services:supersede-duplicate-runs', ['--execute' => true])
            ->assertSuccessful();

        $this->assertNotNull($weakRun->fresh()->superseded_at);
        $this->assertNull($strongRun->fresh()->superseded_at);
    }

    #[Test]
    public function superseded_run_sections_drop_out_of_the_review_queue(): void
    {
        [$service, $weakRun, $strongRun] = $this->serviceWithTwoRuns();

        // Both runs' sections are pending approval — before supersession, both count.
        ServiceSection::query()->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval->value]);

        $query = app(ServiceReviewDashboardQuery::class);
        $before = $query->reviewCandidateSectionCount();

        $this->artisan('services:supersede-duplicate-runs', ['--execute' => true])->assertSuccessful();

        $after = $query->reviewCandidateSectionCount();

        // The weaker run's three sections are excluded; the winner's remain.
        $this->assertSame($before - 3, $after);
    }

    #[Test]
    public function executing_recomputes_the_service_review_phantom_away(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-05',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
            'import_metadata' => [
                'review_triggers' => ['ambiguous_sermon_detection', 'manual_review_sections'],
                'confidence_score' => 1,
            ],
        ]);

        $weakRun = $this->processingRun($service);
        $this->sections($weakRun, [0.3, 0.3, 0.4]); // flagged (needs_manual_review => true)

        $strongRun = $this->processingRun($service);
        $this->sections($strongRun, [0.9, 0.95, 0.98], needsManualReview: false);

        $this->artisan('services:supersede-duplicate-runs', ['--execute' => true])->assertSuccessful();

        $this->assertNotNull($weakRun->fresh()->superseded_at);
        $this->assertNull($strongRun->fresh()->superseded_at);

        $service->refresh();
        $this->assertFalse(
            $service->needs_review,
            'The service self-heals once its only flagged run is superseded.'
        );
        $this->assertArrayNotHasKey('review_triggers', $service->import_metadata?->toArray() ?? []);
    }

    #[Test]
    public function a_dry_run_leaves_service_review_state_untouched(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
            'import_metadata' => ['review_triggers' => ['manual_review_sections'], 'confidence_score' => 1],
        ]);
        $this->sections($this->processingRun($service), [0.3]);
        $this->sections($this->processingRun($service), [0.9, 0.95, 0.98], needsManualReview: false);

        $this->artisan('services:supersede-duplicate-runs')->assertSuccessful();

        $service->refresh();
        $this->assertTrue($service->needs_review);
        $this->assertSame(
            ['manual_review_sections'],
            $service->import_metadata?->toArray()['review_triggers'] ?? null,
        );
    }

    /**
     * @return array{0: ChurchService, 1: MediaProcessingLog, 2: MediaProcessingLog}
     */
    private function serviceWithTwoRuns(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning->value,
        ]);

        $weakRun = $this->processingRun($service);
        $this->sections($weakRun, [0.3, 0.3, 0.4]);

        $strongRun = $this->processingRun($service);
        $this->sections($strongRun, [0.9, 0.95, 0.98]);

        return [$service, $weakRun, $strongRun];
    }

    private function processingRun(ChurchService $service): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $service->id,
            'extracted_date' => $service->date,
            'extracted_service' => $service->service,
        ]);
    }

    /**
     * @param  list<float>  $confidences
     */
    private function sections(MediaProcessingLog $run, array $confidences, bool $needsManualReview = true): void
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

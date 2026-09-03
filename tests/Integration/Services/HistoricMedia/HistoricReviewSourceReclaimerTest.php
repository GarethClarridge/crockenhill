<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricReviewSourceReclaimer;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricReviewSourceReclaimerTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config()->set(
            'filesystems.disks.historic_staging.root',
            Storage::disk('historic_staging')->path(''),
        );
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');
        config()->set('thumbnail-generation.storage.disk', 'historic_staging');
    }

    #[Test]
    public function it_reclaims_a_source_after_the_last_review_obligation_is_resolved(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $sourcePath = 'temp/review-source.mp4';
        $run = $this->createRun($operation, $context, $sourcePath);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/{$sourcePath}",
            str_repeat('r', 17),
        );

        $retained = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(0, $retained['eligible']);
        $this->assertSame(1, $retained['skipped']);
        Storage::disk('historic_staging')->assertExists("{$context->batchRoot}/{$sourcePath}");

        $section->update([
            'publication_status' => ServiceSectionPublicationStatus::Rejected,
            'needs_manual_review' => false,
        ]);

        $reclaimed = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(1, $reclaimed['eligible']);
        $this->assertSame(1, $reclaimed['deleted']);
        $this->assertSame(17, $reclaimed['bytes']);
        Storage::disk('historic_staging')->assertMissing("{$context->batchRoot}/{$sourcePath}");

        $secondSweep = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(0, $secondSweep['eligible']);
        $this->assertSame(0, $secondSweep['deleted']);
        $this->assertSame(0, $secondSweep['bytes']);
    }

    #[Test]
    public function it_does_not_reclaim_a_source_while_m2_work_still_references_it(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $sourcePath = 'temp/retryable-source.mp4';
        $run = $this->createRun($operation, $context, $sourcePath);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $run->id,
            'job_key' => StoreSermonVideo::nestedJobKey($run->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);
        Storage::disk('historic_staging')->put("{$context->batchRoot}/{$sourcePath}", 'retryable');

        $stats = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(0, $stats['eligible']);
        $this->assertSame(1, $stats['skipped']);
        Storage::disk('historic_staging')->assertExists("{$context->batchRoot}/{$sourcePath}");
    }

    #[Test]
    public function it_does_not_reclaim_a_source_while_a_queued_childrens_talk_recut_is_waiting(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $sourcePath = 'temp/queued-recut-source.mp4';
        $run = $this->createRun($operation, $context, $sourcePath);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);
        PrepareSectionPublicationCandidates::registerHistoricNestedJob($run);

        // The operator can resolve the section before the queue worker starts;
        // the queued preparation itself must still retain its source.
        $section->update([
            'publication_status' => ServiceSectionPublicationStatus::Rejected,
            'needs_manual_review' => false,
        ]);
        Storage::disk('historic_staging')->put(
            "{$context->batchRoot}/{$sourcePath}",
            str_repeat('q', 19),
        );

        $stats = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(0, $stats['eligible']);
        $this->assertSame(1, $stats['skipped']);
        Storage::disk('historic_staging')->assertExists("{$context->batchRoot}/{$sourcePath}");
    }

    /**
     * D4: retirement withdraws the result but leaves the run `Failed`, and the
     * status test runs before the obligation test — so a retired run could never
     * be released by settling anything else. #931, #934, #935 and #959 sat
     * retired, failed and still pinning their sources because of it.
     */
    #[Test]
    public function it_reclaims_a_retired_run_that_is_still_failed_and_still_flagged(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = $this->stagingContextFor($operation);
        $sourcePath = 'temp/retired-source.mp4';
        $run = $this->createRun($operation, $context, $sourcePath);
        $run->forceFill([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
        ])->save();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);
        Storage::disk('historic_staging')->put("{$context->batchRoot}/{$sourcePath}", str_repeat('r', 11));

        $held = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(0, $held['deleted']);
        Storage::disk('historic_staging')->assertExists("{$context->batchRoot}/{$sourcePath}");

        $run->forceFill(['superseded_at' => now()])->save();

        $reclaimed = app(HistoricReviewSourceReclaimer::class)->sweep();

        $this->assertSame(1, $reclaimed['deleted']);
        $this->assertSame(11, $reclaimed['bytes']);
        Storage::disk('historic_staging')->assertMissing("{$context->batchRoot}/{$sourcePath}");
    }

    private function createRun(
        HistoricImportOperation $operation,
        HistoricStagingContext $context,
        string $sourcePath,
    ): MediaProcessingLog {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => $sourcePath,
            'processing_metadata' => [
                'historic_import' => [
                    'manifest_item_key' => 'review-source',
                    'staging_context' => $context->toArray(),
                ],
            ],
        ]);
    }

    private function stagingContextFor(HistoricImportOperation $operation): HistoricStagingContext
    {
        return app(HistoricStagingGuard::class)->contextForApprovedPlan(
            $operation->manifest_hashes['video'],
            $operation->plan_hash,
        );
    }
}

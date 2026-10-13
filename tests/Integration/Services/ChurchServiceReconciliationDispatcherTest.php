<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ChurchServiceReconciliationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceReconciliationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_appends_reconciliation_trigger_history_without_clobbering_existing_entries(): void
    {
        Bus::fake();

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-15',
            'service' => SermonService::Morning->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
            'processing_metadata' => [
                'reconciliation_triggers' => [
                    [
                        'triggered_at' => '2026-03-15T08:30:00+00:00',
                        'event' => 'existing_trigger',
                    ],
                ],
            ],
        ]);

        $count = app(ChurchServiceReconciliationDispatcher::class)->dispatchForChurchService($churchService, [
            'event' => 'church_service_canonical_list_changed',
            'source' => 'openlp',
        ]);

        $processingLog->refresh();

        $history = $processingLog->processing_metadata['reconciliation_triggers'] ?? [];

        $this->assertSame(1, $count);
        $this->assertCount(2, $history);
        $this->assertSame('existing_trigger', $history[0]['event']);
        $this->assertSame('church_service_canonical_list_changed', $history[1]['event']);
        $this->assertSame('openlp', $history[1]['source']);
        $this->assertArrayHasKey('triggered_at', $history[1]);

        Bus::assertDispatched(ReconcileServiceSections::class, 1);
    }

    #[Test]
    public function it_does_not_mutate_trigger_history_when_context_is_empty(): void
    {
        Bus::fake();

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-16',
            'service' => SermonService::Evening->value,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
            'processing_metadata' => [
                'existing' => 'value',
            ],
        ]);

        $count = app(ChurchServiceReconciliationDispatcher::class)->dispatchForChurchService($churchService);

        $processingLog->refresh();

        $this->assertSame(1, $count);
        $this->assertSame(['existing' => 'value'], $processingLog->processing_metadata?->toArray() ?? []);
        Bus::assertDispatched(ReconcileServiceSections::class, 1);
    }

    #[Test]
    public function it_dispatches_once_per_matching_completed_livestream_run(): void
    {
        Bus::fake();

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-17',
            'service' => SermonService::Morning->value,
        ]);

        $matchedByColumns = MediaProcessingLog::factory()->livestream()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        $matchedSecond = MediaProcessingLog::factory()->livestream()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        MediaProcessingLog::factory()->livestream()->pending()->create([
            'status' => ProcessingStatus::Pending,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        MediaProcessingLog::factory()->audio()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'status' => ProcessingStatus::Completed,
            'extracted_date' => '2026-03-18',
            'extracted_service' => $churchService->service,
        ]);

        $count = app(ChurchServiceReconciliationDispatcher::class)->dispatchForChurchService($churchService, [
            'event' => 'manual_test',
        ]);

        $this->assertSame(2, $count);

        Bus::assertDispatched(ReconcileServiceSections::class, function (ReconcileServiceSections $job) use ($matchedByColumns, $matchedSecond): bool {
            return $job->processingLog instanceof MediaProcessingLog
                && in_array($job->processingLog->id, [$matchedByColumns->id, $matchedSecond->id], true);
        });
        Bus::assertDispatched(ReconcileServiceSections::class, 2);
    }

    /**
     * Full pass 2026-10-11: an order-of-service answer re-detected run 943, superseded an hour
     * earlier by run 1148 for the same service, and the superseded run failed detection.
     */
    #[Test]
    public function a_superseded_run_is_not_reconciled(): void
    {
        Bus::fake();

        $churchService = ChurchService::factory()->create([
            'date' => '2026-03-19',
            'service' => SermonService::Morning->value,
        ]);

        $current = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => $churchService->date,
            'extracted_service' => $churchService->service,
            'superseded_at' => now(),
            'superseded_by_processing_log_id' => $current->id,
        ]);

        $count = app(ChurchServiceReconciliationDispatcher::class)->dispatchForChurchService($churchService);

        $this->assertSame(1, $count);
        Bus::assertDispatched(ReconcileServiceSections::class, fn (ReconcileServiceSections $job): bool => $job->processingLog->is($current));
        Bus::assertDispatched(ReconcileServiceSections::class, 1);
    }
}

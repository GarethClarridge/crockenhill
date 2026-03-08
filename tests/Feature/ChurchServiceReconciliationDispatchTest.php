<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceReconciliationDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_reconciliation_when_a_matching_service_is_created_after_processing(): void
    {
        Queue::fake();

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-04-19',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-04-19',
            'service' => SermonService::MORNING->value,
        ]);

        Queue::assertPushed(
            ReconcileServiceSections::class,
            fn (ReconcileServiceSections $job): bool => $job->processingLogId() === $processingLog->id
                && $job->churchServiceId() === $churchService->id
        );
    }

    #[Test]
    public function it_redispatches_reconciliation_when_service_type_changes(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-04-26',
            'extracted_service' => SermonService::EVENING->value,
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-04-26',
            'service' => SermonService::MORNING->value,
        ]);

        Queue::fake();

        $churchService->update([
            'service' => SermonService::EVENING->value,
        ]);

        Queue::assertPushed(
            ReconcileServiceSections::class,
            fn (ReconcileServiceSections $job): bool => $job->processingLogId() === $processingLog->id
                && $job->churchServiceId() === $churchService->id
        );
    }

    #[Test]
    public function it_does_not_dispatch_reconciliation_when_unrelated_fields_are_updated(): void
    {
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-04-26',
            'extracted_service' => SermonService::EVENING->value,
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-04-26',
            'service' => SermonService::EVENING->value,
        ]);

        Queue::fake();

        $churchService->update([
            'needs_review' => true,
        ]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_reconciliation_without_a_matching_completed_processing_run(): void
    {
        Queue::fake();

        MediaProcessingLog::factory()->livestream()->create([
            'status' => 'processing',
            'extracted_date' => '2026-05-03',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        ChurchService::factory()->create([
            'date' => '2026-05-03',
            'service' => SermonService::MORNING->value,
        ]);

        Queue::assertNothingPushed();
    }
}

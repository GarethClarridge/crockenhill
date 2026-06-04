<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Sermon\LivestreamSegmentationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnifiedMediaProcessorStatusTest extends TestCase
{
    #[Test]
    public function can_handle_does_not_resolve_livestream_services(): void
    {
        $this->app->bind(LivestreamSegmentationService::class, function (): never {
            throw new \RuntimeException('LivestreamSegmentationService should not be resolved for status checks.');
        });
        $this->app->forgetInstance(UnifiedMediaProcessor::class);

        $processor = $this->app->make(UnifiedMediaProcessor::class);

        $this->assertFalse($processor->canHandle('missing-processing-id'));
    }

    #[Test]
    public function get_status_does_not_resolve_livestream_services(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create();

        $this->app->bind(LivestreamSegmentationService::class, function (): never {
            throw new \RuntimeException('LivestreamSegmentationService should not be resolved for status checks.');
        });
        $this->app->forgetInstance(UnifiedMediaProcessor::class);

        $processor = $this->app->make(UnifiedMediaProcessor::class);
        $response = $processor->getStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertSame($log->processing_id, $response->processingId);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Data\ProcessingResult;
use App\Data\SermonAnalysis;
use App\Services\Processing\UnifiedMediaProcessor;

trait MediaProcessingTestHelpers
{
    use BuildsTestScenarios;

    /**
     * Create a mock SermonAnalysis object for testing.
     */
    protected function createMockSermonAnalysis(string $title = 'God\'s Amazing Love'): SermonAnalysis
    {
        return SermonAnalysis::create(
            title: $title,
            series: 'John Study',
            reference: 'John 3:16-21',
            points: ['First Point', 'Second Point', 'Third Point'],
            summary: 'A sermon about God\'s amazing love for humanity.',
            transcript: 'This is a sample sermon transcript about God\'s amazing love and grace. It contains meaningful content that demonstrates the depth of God\'s love for humanity and how we should respond to that love in our daily lives. This transcript is long enough to pass validation checks.'
        );
    }

    /**
     * Mock UnifiedMediaProcessor to return a successful result for any type.
     */
    protected function mockUnifiedMediaProcessor(?string $processingId = null): UnifiedMediaProcessor
    {
        $processingId = $processingId ?? 'test-uuid-123';

        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockResult = ProcessingResult::success(
            processingId: $processingId,
            message: 'Processing initiated successfully',
            statusUrl: "/api/media/processing/{$processingId}/status"
        );
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        return $mockProcessor;
    }
}

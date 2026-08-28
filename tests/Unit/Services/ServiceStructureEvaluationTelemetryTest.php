<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ChurchService\Structure\ServiceStructureEvaluationTelemetry;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEvaluationTelemetryTest extends TestCase
{
    #[Test]
    public function it_extracts_and_then_clears_usage(): void
    {
        $telemetry = new ServiceStructureEvaluationTelemetry;

        $telemetry->record(CreateResponse::fake([
            'usage' => [
                'prompt_tokens' => 4000,
                'completion_tokens' => 250,
                'total_tokens' => 4250,
                'prompt_tokens_details' => ['cached_tokens' => 1000],
                'completion_tokens_details' => ['reasoning_tokens' => 90],
            ],
        ]));

        $this->assertSame([
            'input_tokens' => 4000,
            'cached_input_tokens' => 1000,
            'output_tokens' => 250,
            'reasoning_tokens' => 90,
            'total_tokens' => 4250,
        ], $telemetry->take());

        // Consuming clears it, so a later entry can't inherit an earlier one's usage.
        $this->assertNull($telemetry->take());
    }

    #[Test]
    public function a_response_without_usage_leaves_nothing_to_take(): void
    {
        $telemetry = new ServiceStructureEvaluationTelemetry;

        $telemetry->record(CreateResponse::fake(['usage' => null]));

        $this->assertNull($telemetry->take());
    }
}

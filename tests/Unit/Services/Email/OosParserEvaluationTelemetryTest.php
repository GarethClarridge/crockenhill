<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\OosParserEvaluationTelemetry;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosParserEvaluationTelemetryTest extends TestCase
{
    #[Test]
    public function it_keys_each_call_by_source_attempt_and_role(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $telemetry->beginRun();
        $telemetry->beginSource('email-001');
        $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

        $calls = $telemetry->finishSource();

        $this->assertSame('email-001', $calls[0]['source_key']);
        $this->assertSame('extract', $calls[0]['call_role']);
        $this->assertSame(1, $calls[0]['attempt']);
        $this->assertFalse($calls[0]['usage_missing']);
        $this->assertSame('gpt-5.4-nano', $telemetry->returnedModel());
    }

    #[Test]
    public function it_refuses_when_the_provider_changes_model_within_an_arm(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $telemetry->beginRun();
        $telemetry->beginSource('email-001');
        $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.6-luna']), 'extract', 1, microtime(true));
        $telemetry->finishSource();
        $telemetry->beginSource('email-002');
        $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.6-luna-2026-08-17']), 'extract', 1, microtime(true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed returned model');

        $telemetry->finishSource();
    }
}

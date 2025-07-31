<?php

namespace Tests\Unit;

use App\Services\SermonAnalysisService;
use Exception;
use Tests\TestCase;

class SermonAnalysisTest extends TestCase
{
  public function test_service_can_be_instantiated_with_api_key(): void
  {
    config(['sermon-processing.analysis.openai_api_key' => 'test-api-key']);

    $logger = app(\App\Services\SermonProcessingLogger::class);
    $service = new SermonAnalysisService($logger);
    $this->assertInstanceOf(SermonAnalysisService::class, $service);
  }

  public function test_service_throws_exception_without_api_key(): void
  {
    config(['sermon-processing.analysis.openai_api_key' => null]);
    config(['openai.api_key' => null]);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('OpenAI API key not configured for analysis service');

    $logger = app(\App\Services\SermonProcessingLogger::class);
    new SermonAnalysisService($logger);
  }
}

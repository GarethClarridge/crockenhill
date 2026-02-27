<?php

namespace Tests\Unit;

use App\Repositories\SermonRepository;
use App\Services\BritishEnglishConverter;
use App\Services\SermonAnalysisPromptBuilder;
use App\Services\SermonAnalysisService;
use App\Services\SermonAnalysisValidator;
use Exception;
use Tests\TestCase;

class SermonAnalysisTest extends TestCase
{
    public function test_service_can_be_instantiated_with_api_key(): void
    {
        config(['media-processing.analysis.openai_api_key' => 'test-api-key']);

        $logger = app(\App\Services\SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class));
        $promptBuilder = new SermonAnalysisPromptBuilder($validator);
        $service = new SermonAnalysisService($logger, $repository, $validator, $promptBuilder);
        $this->assertInstanceOf(SermonAnalysisService::class, $service);
    }

    public function test_service_throws_exception_without_api_key(): void
    {
        config(['media-processing.analysis.service' => 'openai']);
        config(['media-processing.analysis.openai_api_key' => null]);
        config(['openai.api_key' => null]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key not configured for analysis service');

        $logger = app(\App\Services\SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class));
        $promptBuilder = new SermonAnalysisPromptBuilder($validator);
        new SermonAnalysisService($logger, $repository, $validator, $promptBuilder);
    }
}

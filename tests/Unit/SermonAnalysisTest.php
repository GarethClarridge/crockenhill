<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BritishEnglishConverter;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonAnalysisPromptBuilder;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonAnalysisValidator;
use Exception;
use TechWilk\BibleVerseParser\BiblePassageParser;
use Tests\TestCase;

class SermonAnalysisTest extends TestCase
{
    public function test_service_can_be_instantiated_with_api_key(): void
    {
        config(['media-processing.analysis.openai_api_key' => 'test-api-key']);

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), new BiblePassageParser);
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

        $logger = app(SermonProcessingLogger::class);
        $repository = app(SermonRepository::class);
        $validator = new SermonAnalysisValidator(app(BritishEnglishConverter::class), new BiblePassageParser);
        $promptBuilder = new SermonAnalysisPromptBuilder($validator);
        new SermonAnalysisService($logger, $repository, $validator, $promptBuilder);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Contracts\OosEmailItemExtractor;
use App\Contracts\SermonAnalysisInterface;
use App\Contracts\TranscriptionServiceInterface;
use App\Services\Email\OpenAiOosEmailItemExtractor;
use App\Services\Media\Audio\MockTranscriptionService;
use App\Services\MockSermonAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiServiceProviderTest extends TestCase
{
    #[Test]
    public function sermon_analysis_interface_resolves_to_mock_when_configured(): void
    {
        config()->set('media-processing.analysis.service', 'mock');
        $this->app->forgetInstance(SermonAnalysisInterface::class);

        $this->assertInstanceOf(MockSermonAnalysisService::class, $this->app->make(SermonAnalysisInterface::class));
    }

    #[Test]
    public function transcription_service_interface_resolves_to_mock_when_configured(): void
    {
        config()->set('media-processing.transcription.service', 'mock');
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(MockTranscriptionService::class, $this->app->make(TranscriptionServiceInterface::class));
    }

    #[Test]
    public function oos_email_item_extractor_resolves_to_openai_implementation(): void
    {
        $this->assertInstanceOf(OpenAiOosEmailItemExtractor::class, $this->app->make(OosEmailItemExtractor::class));
    }
}

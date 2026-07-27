<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Contracts\OosEmailItemExtractor;
use App\Contracts\SermonAnalysisInterface;
use App\Contracts\ServiceStructureInterface;
use App\Contracts\ServiceTranscriptionInterface;
use App\Contracts\TranscriptionServiceInterface;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\ChurchService\Structure\OpenAiServiceStructureService;
use App\Services\Email\OpenAiOosEmailItemExtractor;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\LocalWhisperTranscriptionService;
use App\Services\Media\Audio\MockServiceTranscriptionService;
use App\Services\Media\Audio\MockTranscriptionService;
use App\Services\Media\Audio\OpenAiServiceTranscriptionService;
use App\Services\Sermon\MockSermonAnalysisService;
use App\Services\Sermon\SermonAnalysisService;
use InvalidArgumentException;
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
    public function sermon_analysis_interface_resolves_to_openai_when_configured(): void
    {
        config()->set('media-processing.analysis.service', 'openai');
        config()->set('media-processing.analysis.openai_api_key', 'test-key-123');
        $this->app->forgetInstance(SermonAnalysisInterface::class);

        $this->assertInstanceOf(SermonAnalysisService::class, $this->app->make(SermonAnalysisInterface::class));
    }

    #[Test]
    public function sermon_analysis_interface_throws_on_an_unknown_service(): void
    {
        config()->set('media-processing.analysis.service', 'carrier-pigeon');
        $this->app->forgetInstance(SermonAnalysisInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrier-pigeon');

        $this->app->make(SermonAnalysisInterface::class);
    }

    #[Test]
    public function transcription_service_interface_resolves_to_mock_when_configured(): void
    {
        config()->set('media-processing.transcription.service', 'mock');
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(MockTranscriptionService::class, $this->app->make(TranscriptionServiceInterface::class));
    }

    #[Test]
    public function transcription_service_interface_resolves_to_local_when_configured(): void
    {
        config()->set('media-processing.transcription.service', 'local');
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(LocalWhisperTranscriptionService::class, $this->app->make(TranscriptionServiceInterface::class));
    }

    #[Test]
    public function transcription_service_interface_resolves_to_openai_when_configured(): void
    {
        config()->set('media-processing.transcription.service', 'openai');
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(AudioTranscriptionService::class, $this->app->make(TranscriptionServiceInterface::class));
    }

    #[Test]
    public function transcription_service_interface_throws_on_an_unknown_service(): void
    {
        config()->set('media-processing.transcription.service', 'carrier-pigeon');
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrier-pigeon');

        $this->app->make(TranscriptionServiceInterface::class);
    }

    #[Test]
    public function oos_email_item_extractor_resolves_to_openai_implementation(): void
    {
        $this->assertInstanceOf(OpenAiOosEmailItemExtractor::class, $this->app->make(OosEmailItemExtractor::class));
    }

    #[Test]
    public function service_transcription_interface_defaults_to_mock(): void
    {
        $this->assertInstanceOf(
            MockServiceTranscriptionService::class,
            $this->app->make(ServiceTranscriptionInterface::class)
        );
    }

    #[Test]
    public function service_transcription_interface_resolves_openai_and_local_implementations(): void
    {
        config()->set('media-processing.service_structure.transcription_service', 'openai');
        $this->assertInstanceOf(
            OpenAiServiceTranscriptionService::class,
            $this->app->make(ServiceTranscriptionInterface::class)
        );

        config()->set('media-processing.service_structure.transcription_service', 'local');
        $this->assertInstanceOf(
            LocalWhisperServiceTranscriptionService::class,
            $this->app->make(ServiceTranscriptionInterface::class)
        );
    }

    #[Test]
    public function service_transcription_interface_throws_on_an_unknown_service(): void
    {
        config()->set('media-processing.service_structure.transcription_service', 'carrier-pigeon');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrier-pigeon');

        $this->app->make(ServiceTranscriptionInterface::class);
    }

    #[Test]
    public function service_structure_interface_defaults_to_mock(): void
    {
        $this->assertInstanceOf(
            MockServiceStructureService::class,
            $this->app->make(ServiceStructureInterface::class)
        );
    }

    #[Test]
    public function service_structure_interface_resolves_the_openai_detector(): void
    {
        config()->set('media-processing.service_structure.detector', 'openai');

        $this->assertInstanceOf(
            OpenAiServiceStructureService::class,
            $this->app->make(ServiceStructureInterface::class)
        );
    }

    #[Test]
    public function service_structure_interface_throws_on_an_unknown_detector(): void
    {
        config()->set('media-processing.service_structure.detector', 'crystal-ball');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crystal-ball');

        $this->app->make(ServiceStructureInterface::class);
    }
}

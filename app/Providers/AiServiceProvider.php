<?php

declare(strict_types=1);

namespace App\Providers;

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
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SermonAnalysisInterface::class, function ($app): SermonAnalysisInterface {
            $serviceType = (string) config('media-processing.analysis.service', 'openai');

            return match ($serviceType) {
                'mock' => $app->make(MockSermonAnalysisService::class),
                'openai' => $app->make(SermonAnalysisService::class),
                default => throw new InvalidArgumentException(
                    "Unknown sermon analysis service [{$serviceType}]; expected mock or openai."
                ),
            };
        });

        $this->app->bind(TranscriptionServiceInterface::class, function ($app): TranscriptionServiceInterface {
            $serviceType = (string) config('media-processing.transcription.service', 'openai');

            return match ($serviceType) {
                'mock' => $app->make(MockTranscriptionService::class),
                'local' => $app->make(LocalWhisperTranscriptionService::class),
                'openai' => $app->make(AudioTranscriptionService::class),
                default => throw new InvalidArgumentException(
                    "Unknown transcription service [{$serviceType}]; expected mock, local or openai."
                ),
            };
        });

        $this->app->bind(ServiceTranscriptionInterface::class, function ($app): ServiceTranscriptionInterface {
            $serviceType = (string) config('media-processing.service_structure.transcription_service', 'mock');

            return match ($serviceType) {
                'mock' => $app->make(MockServiceTranscriptionService::class),
                'local' => $app->make(LocalWhisperServiceTranscriptionService::class),
                'openai' => $app->make(OpenAiServiceTranscriptionService::class),
                default => throw new InvalidArgumentException(
                    "Unknown service structure transcription service [{$serviceType}]; expected mock, local or openai."
                ),
            };
        });

        $this->app->bind(ServiceStructureInterface::class, function ($app): ServiceStructureInterface {
            $detector = (string) config('media-processing.service_structure.detector', 'mock');

            return match ($detector) {
                'mock' => $app->make(MockServiceStructureService::class),
                'openai' => $app->make(OpenAiServiceStructureService::class),
                default => throw new InvalidArgumentException(
                    "Unknown service structure detector [{$detector}]; expected mock or openai."
                ),
            };
        });

        $this->app->bind(OosEmailItemExtractor::class, OpenAiOosEmailItemExtractor::class);
    }
}

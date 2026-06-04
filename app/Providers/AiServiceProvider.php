<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\OosEmailItemExtractor;
use App\Contracts\SermonAnalysisInterface;
use App\Contracts\TranscriptionServiceInterface;
use App\Services\AudioTranscriptionService;
use App\Services\Email\OpenAiOosEmailItemExtractor;
use App\Services\LocalWhisperTranscriptionService;
use App\Services\MockSermonAnalysisService;
use App\Services\MockTranscriptionService;
use App\Services\SermonAnalysisService;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SermonAnalysisInterface::class, function ($app): SermonAnalysisInterface {
            $serviceType = config('media-processing.analysis.service', 'openai');

            if ($serviceType === 'mock') {
                return $app->make(MockSermonAnalysisService::class);
            }

            return $app->make(SermonAnalysisService::class);
        });

        $this->app->bind(TranscriptionServiceInterface::class, function ($app): TranscriptionServiceInterface {
            $serviceType = config('media-processing.transcription.service', 'openai');

            return match ($serviceType) {
                'mock' => $app->make(MockTranscriptionService::class),
                'local' => $app->make(LocalWhisperTranscriptionService::class),
                default => $app->make(AudioTranscriptionService::class),
            };
        });

        $this->app->bind(OosEmailItemExtractor::class, OpenAiOosEmailItemExtractor::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\OosEmailItemExtractor;
use App\Contracts\SermonAnalysisInterface;
use App\Contracts\TranscriptionServiceInterface;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Listeners\DispatchChurchServiceReconciliation;
use App\Services\AudioTranscriptionService;
use App\Services\MockSermonAnalysisService;
use App\Services\MockTranscriptionService;
use App\Services\OpenAiOosEmailItemExtractor;
use App\Services\SermonAnalysisService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * This service provider is a great spot to register your container
     * bindings with the application.
     */
    public function register(): void
    {
        $this->app->bind('path.public', function (): string {
            return base_path().'/public';
        });

        // Bind sermon analysis service based on environment configuration
        $this->app->bind(SermonAnalysisInterface::class, function ($app): SermonAnalysisInterface {
            $serviceType = config('media-processing.analysis.service', 'openai');

            if ($serviceType === 'mock') {
                return $app->make(MockSermonAnalysisService::class);
            }

            return $app->make(SermonAnalysisService::class);
        });

        // Bind transcription service based on environment configuration
        $this->app->bind(TranscriptionServiceInterface::class, function ($app): TranscriptionServiceInterface {
            $serviceType = config('media-processing.transcription.service', 'openai');

            return match ($serviceType) {
                'mock' => $app->make(MockTranscriptionService::class),
                default => $app->make(AudioTranscriptionService::class),
            };
        });

        $this->app->bind(OosEmailItemExtractor::class, OpenAiOosEmailItemExtractor::class);
    }

    public function boot(): void
    {
        Event::listen(
            ChurchServiceCanonicalListChanged::class,
            DispatchChurchServiceReconciliation::class,
        );

        Password::defaults(function () {
            return Password::min(12)
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });
    }
}

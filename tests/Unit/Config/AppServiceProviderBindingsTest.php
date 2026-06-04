<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Contracts\SermonAnalysisInterface;
use App\Contracts\TranscriptionServiceInterface;
use App\Services\Media\Audio\MockTranscriptionService;
use App\Services\Sermon\MockSermonAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppServiceProviderBindingsTest extends TestCase
{
    #[Test]
    public function core_service_contracts_resolve_from_the_container(): void
    {
        config()->set('media-processing.analysis.service', 'mock');
        config()->set('media-processing.transcription.service', 'mock');

        $this->app->forgetInstance(SermonAnalysisInterface::class);
        $this->app->forgetInstance(TranscriptionServiceInterface::class);

        $analysisService = $this->app->make(SermonAnalysisInterface::class);
        $transcriptionService = $this->app->make(TranscriptionServiceInterface::class);

        $this->assertInstanceOf(MockSermonAnalysisService::class, $analysisService);
        $this->assertInstanceOf(MockTranscriptionService::class, $transcriptionService);
    }

    #[Test]
    public function legacy_auth_registrar_binding_is_not_registered(): void
    {
        $this->assertFalse(
            $this->app->bound('Illuminate\Contracts\Auth\Registrar'),
            'Legacy Illuminate\\Contracts\\Auth\\Registrar binding should not be registered.'
        );
    }
}

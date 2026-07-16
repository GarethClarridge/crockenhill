<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Services\Preacher\NullSpeakerIdentificationService;
use App\Services\Preacher\ResemblyzerSpeakerIdentificationService;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Sermon\LivestreamSegmentationService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingServiceProviderTest extends TestCase
{
    #[Test]
    public function it_resolves_unified_media_processor_from_the_container(): void
    {
        $this->assertInstanceOf(UnifiedMediaProcessor::class, app(UnifiedMediaProcessor::class));
    }

    #[Test]
    public function it_resolves_livestream_segmentation_service_from_the_container(): void
    {
        $this->assertInstanceOf(LivestreamSegmentationService::class, app(LivestreamSegmentationService::class));
    }

    #[Test]
    public function it_resolves_speaker_identification_interface_to_null_service_by_default(): void
    {
        Config::set('media-processing.speaker_identification.provider', 'null');

        // Forget the cached binding so config change takes effect
        $this->app->forgetInstance(SpeakerIdentificationInterface::class);

        $resolved = app(SpeakerIdentificationInterface::class);

        $this->assertInstanceOf(SpeakerIdentificationInterface::class, $resolved);
        $this->assertInstanceOf(NullSpeakerIdentificationService::class, $resolved);
    }

    #[Test]
    public function it_resolves_speaker_identification_interface_to_resemblyzer_when_configured(): void
    {
        Config::set('media-processing.speaker_identification.provider', 'resemblyzer');

        $this->app->forgetInstance(SpeakerIdentificationInterface::class);

        $resolved = app(SpeakerIdentificationInterface::class);

        $this->assertInstanceOf(SpeakerIdentificationInterface::class, $resolved);
        $this->assertInstanceOf(ResemblyzerSpeakerIdentificationService::class, $resolved);
    }

    #[Test]
    public function it_falls_back_to_null_service_for_unknown_speaker_identification_providers(): void
    {
        Config::set('media-processing.speaker_identification.provider', 'unknown-provider');

        $this->app->forgetInstance(SpeakerIdentificationInterface::class);

        $resolved = app(SpeakerIdentificationInterface::class);

        $this->assertInstanceOf(NullSpeakerIdentificationService::class, $resolved);
    }
}

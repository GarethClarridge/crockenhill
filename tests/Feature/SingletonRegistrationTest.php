<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Seo\SermonItemListPresenter;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SingletonRegistrationTest extends TestCase
{
    #[Test]
    public function it_resolves_sermon_related_services_as_scoped_instances(): void
    {
        $services = [
            SermonExposurePolicy::class,
            SermonStorageService::class,
            SermonTranscriptReader::class,
            TranscriptStorageService::class,
            SermonItemListPresenter::class,
        ];

        foreach ($services as $service) {
            $instance1 = $this->app->make($service);
            $instance2 = $this->app->make($service);

            $this->assertSame($instance1, $instance2, "Service [{$service}] should be shared within the current scope");

            $this->app->forgetScopedInstances();

            $instance3 = $this->app->make($service);

            $this->assertNotSame($instance1, $instance3, "Service [{$service}] should be refreshed when the scope resets");
        }
    }
}

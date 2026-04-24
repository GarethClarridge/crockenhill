<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presenters\SermonItemListPresenter;
use App\Presenters\SermonViewPresenter;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\SermonTranscriptReader;
use App\Services\TranscriptStorageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SingletonRegistrationTest extends TestCase
{
    #[Test]
    public function it_resolves_sermon_related_services_as_singletons(): void
    {
        $services = [
            SermonExposurePolicy::class,
            SermonStorageService::class,
            SermonTranscriptReader::class,
            TranscriptStorageService::class,
            SermonViewPresenter::class,
            SermonItemListPresenter::class,
        ];

        foreach ($services as $service) {
            $instance1 = $this->app->make($service);
            $instance2 = $this->app->make($service);

            $this->assertSame($instance1, $instance2, "Service [{$service}] should be a singleton");
        }
    }
}

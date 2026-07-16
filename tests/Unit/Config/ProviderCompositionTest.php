<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Providers\AiServiceProvider;
use App\Providers\ChurchServiceDomainServiceProvider;
use App\Providers\ModelObserverServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\UrlServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderCompositionTest extends TestCase
{
    #[Test]
    public function bootstrap_providers_register_domain_specific_bootstrapping_providers(): void
    {
        /** @var array<int, class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $this->assertContains(AiServiceProvider::class, $providers);
        $this->assertContains(ChurchServiceDomainServiceProvider::class, $providers);
        $this->assertContains(UrlServiceProvider::class, $providers);
        $this->assertContains(ModelObserverServiceProvider::class, $providers);
        $this->assertContains(RateLimitServiceProvider::class, $providers);
    }

    #[Test]
    public function bootstrap_providers_does_not_include_empty_test_service_provider(): void
    {
        /** @var array<int, class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $this->assertNotContains('App\Providers\TestServiceProvider', $providers);
    }
}

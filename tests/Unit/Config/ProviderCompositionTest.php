<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Providers\ModelObserverServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\UrlServiceProvider;
use App\Providers\ViewServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderCompositionTest extends TestCase
{
    #[Test]
    public function bootstrap_providers_register_domain_specific_bootstrapping_providers(): void
    {
        /** @var array<int, class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $this->assertContains(UrlServiceProvider::class, $providers);
        $this->assertContains(ViewServiceProvider::class, $providers);
        $this->assertContains(ModelObserverServiceProvider::class, $providers);
        $this->assertContains(RateLimitServiceProvider::class, $providers);
    }
}

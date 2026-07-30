<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConvergeHistoricChurchServiceCommandTest extends TestCase
{
    #[Test]
    public function it_fails_without_reading_or_writing_when_a_bundle_is_missing(): void
    {
        $this->artisan('service-tracking:converge-historic-service', [
            'media-bundle' => storage_path('app/private/missing-bundle-a.json'),
            'convergence-bundle' => storage_path('app/private/missing-bundle-b.json'),
        ])
            ->expectsOutputToContain('Bundle file is missing:')
            ->assertFailed();
    }
}

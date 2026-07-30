<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportChurchServiceConvergenceCommandTest extends TestCase
{
    #[Test]
    public function it_rejects_an_export_without_reviewed_service_ids(): void
    {
        $this->artisan('service-tracking:export-convergence', [
            '--batch-hash' => str_repeat('1', 64),
            '--media-bundle-hash' => str_repeat('2', 64),
            '--fingerprint' => '{"projector_version":1}',
            '--output' => storage_path('app/private/r8-bundle-b.json'),
        ])
            ->expectsOutput('--service-ids is required.')
            ->assertFailed();
    }
}

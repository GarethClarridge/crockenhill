<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditChurchServiceConvergenceCommandTest extends TestCase
{
    #[Test]
    public function it_fails_when_the_bundle_file_is_missing(): void
    {
        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => storage_path('app/private/missing-r8-bundle.json'),
        ])
            ->expectsOutput('The convergence bundle is missing.')
            ->assertFailed();
    }
}

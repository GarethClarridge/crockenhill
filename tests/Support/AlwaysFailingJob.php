<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A test-only job that always throws, used to trigger Bus::chain catch callbacks.
 */
class AlwaysFailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('Simulated job failure for testing.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Tests\TestCase;

/**
 * Proves the bootstrap wiring (`Integration::handles()`) actually routes
 * reported exceptions to Sentry, without any network call.
 *
 * The SDK captures through the static `SentrySdk::getCurrentHub()`, so rebinding
 * the container is not observed — the hub is swapped explicitly for a spy and
 * restored afterwards.
 */
class SentryCaptureWiringTest extends TestCase
{
    private ?HubInterface $originalHub = null;

    protected function tearDown(): void
    {
        if ($this->originalHub !== null) {
            SentrySdk::setCurrentHub($this->originalHub);
            $this->originalHub = null;
        }

        parent::tearDown();
    }

    #[Test]
    public function reported_exceptions_reach_the_sentry_hub(): void
    {
        $exception = new RuntimeException('layer3-capture-wiring');

        $spyHub = Mockery::mock(HubInterface::class)->shouldIgnoreMissing();
        $spyHub->shouldReceive('captureException')
            ->once()
            ->with($exception, Mockery::any());

        $this->originalHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub($spyHub);

        report($exception);

        // Expectation verified by Mockery on tearDown; assert explicitly so the
        // test never registers as risky.
        $this->addToAssertionCount(1);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TestingLogConfigurationTest extends TestCase
{
    #[Test]
    public function tests_use_the_dedicated_testing_log_channel(): void
    {
        $this->assertSame('testing', config('logging.default'));
        $this->assertSame(
            storage_path('logs/testing.log'),
            config('logging.channels.testing.path')
        );
    }
}

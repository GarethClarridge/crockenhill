<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    #[Test]
    public function default_channels_respect_the_log_level_environment_variable(): void
    {
        putenv('LOG_LEVEL=warning');
        $_ENV['LOG_LEVEL'] = 'warning';
        $_SERVER['LOG_LEVEL'] = 'warning';

        /** @var array{channels: array<string, array<string, mixed>>} $config */
        $config = require config_path('logging.php');

        $this->assertSame('warning', $config['channels']['single']['level']);
        $this->assertSame('warning', $config['channels']['daily']['level']);
        $this->assertSame('warning', $config['channels']['syslog']['level']);
        $this->assertSame('warning', $config['channels']['errorlog']['level']);
    }
}

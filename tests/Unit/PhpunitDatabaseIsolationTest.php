<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhpunitDatabaseIsolationTest extends TestCase
{
    #[Test]
    public function phpunit_cannot_inherit_the_live_database_from_a_clone_environment(): void
    {
        $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
        $this->assertNotFalse($configuration);
        $settings = $configuration->xpath('/phpunit/php/env[@name="DB_DATABASE"]');

        $this->assertIsArray($settings);
        $this->assertCount(1, $settings, 'Every checkout, including clones without .env.testing, must select the test database.');
        $this->assertSame('testing', (string) $settings[0]['value']);
        $this->assertSame('true', (string) $settings[0]['force'], 'An inherited live DB_DATABASE must never override the test database.');
    }
}

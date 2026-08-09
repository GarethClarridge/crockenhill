<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricDefinitiveRuntimeConfigTest extends TestCase
{
    #[Test]
    public function it_requires_pinned_images_durable_datastores_and_persistent_private_paths(): void
    {
        $compose = file_get_contents(base_path('docker-compose.historic.yml'));

        $this->assertIsString($compose);
        $this->assertStringContainsString('HISTORIC_APP_IMAGE:?', $compose);
        $this->assertStringContainsString('HISTORIC_MYSQL_IMAGE:?', $compose);
        $this->assertStringContainsString('HISTORIC_REDIS_IMAGE:?', $compose);
        $this->assertStringContainsString('HISTORIC_WHISPER_IMAGE:?', $compose);
        $this->assertStringContainsString('--innodb-flush-log-at-trx-commit=1', $compose);
        $this->assertStringContainsString('--innodb-doublewrite=ON', $compose);
        $this->assertStringContainsString('--sync-binlog=1', $compose);
        $this->assertStringContainsString('--appendonly yes --appendfsync always', $compose);
        $this->assertStringContainsString('HISTORIC_RUNTIME_PATH:?', $compose);
        $this->assertSame(
            env('HISTORIC_STAGING_ROOT', storage_path('app/private/historic-staging')),
            config('filesystems.disks.historic_staging.root'),
        );
    }
}

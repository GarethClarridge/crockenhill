<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\WorkerCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WorkerCodeTest extends TestCase
{
    /** Only a worker records the code it booted on; a command or a test does not pay to hash it. */
    #[Test]
    public function only_a_queue_worker_records_its_boot_revision(): void
    {
        $this->assertTrue(WorkerCode::isQueueWorker(['artisan', 'queue:work', 'redis', '--queue=historic-llm']));
        $this->assertTrue(WorkerCode::isQueueWorker(['artisan', 'queue:listen']));
        $this->assertFalse(WorkerCode::isQueueWorker(['artisan', 'historic-import:rerun-extract']));
        $this->assertFalse(WorkerCode::isQueueWorker(['vendor/bin/phpunit']));
        $this->assertFalse(WorkerCode::isQueueWorker([]));
    }
}

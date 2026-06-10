<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the cross-file invariant that the redis queue's `retry_after` always
 * exceeds the production worker's `--timeout`. The two values live in files no
 * single runtime reads together (config/queue.php and the supervisord config),
 * so only a test makes the relationship visible. If TRANSCRIPTION_JOB_TIMEOUT
 * (or any other long job) forces the worker `--timeout` up, this fails the build
 * until `retry_after` is raised with it — which is the point.
 */
class QueueRetryAfterInvariantTest extends TestCase
{
    #[Test]
    public function redis_retry_after_exceeds_the_production_worker_timeout(): void
    {
        $supervisordPath = base_path('docker/production/supervisord.conf');

        $this->assertFileExists(
            $supervisordPath,
            'Expected the production supervisord config to exist so the invariant can be checked.',
        );

        $supervisord = (string) file_get_contents($supervisordPath);

        $this->assertSame(
            1,
            preg_match('/--timeout=(\d+)/', $supervisord, $matches),
            'Could not find the queue worker --timeout value in supervisord.conf.',
        );

        $workerTimeout = (int) $matches[1];
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThan(
            $workerTimeout,
            $retryAfter,
            sprintf(
                'queue.connections.redis.retry_after (%d) must exceed the worker --timeout (%d), '
                .'or a long-running job can be released and re-delivered while still running. '
                .'Raise REDIS_QUEUE_RETRY_AFTER (and its default in config/queue.php) above the worker timeout.',
                $retryAfter,
                $workerTimeout,
            ),
        );
    }
}

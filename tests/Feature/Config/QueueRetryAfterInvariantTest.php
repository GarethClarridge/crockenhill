<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use App\Jobs\AssessSermonVideoQuality;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the cross-file invariant that the redis queue's `retry_after` always
 * exceeds every production Horizon supervisor's job `timeout`. Horizon also
 * enforces this at boot (it refuses to start when violated), but that only
 * surfaces at deploy time — this test fails the build instead. If
 * TRANSCRIPTION_JOB_TIMEOUT (or any other long job) forces a supervisor
 * timeout up, this fails until `retry_after` is raised with it — which is the
 * point.
 */
class QueueRetryAfterInvariantTest extends TestCase
{
    #[Test]
    public function video_quality_job_allows_the_whole_file_probe_to_finish_before_its_queue_deadline(): void
    {
        foreach ([900, 1800] as $probeTimeout) {
            config(['media-processing.video_quality.probe.timeout_seconds' => $probeTimeout]);

            $job = new AssessSermonVideoQuality(sermonId: 1);

            $this->assertGreaterThanOrEqual($probeTimeout + 300, $job->timeout);
            $this->assertGreaterThan($job->timeout, $job->uniqueFor);

            foreach (['redis', 'database', 'beanstalkd'] as $connection) {
                $this->assertGreaterThan($job->timeout, (int) config("queue.connections.{$connection}.retry_after"));
            }
        }
    }

    #[Test]
    public function redis_retry_after_exceeds_every_production_horizon_supervisor_timeout(): void
    {
        $defaults = (array) config('horizon.defaults');
        $production = (array) config('horizon.environments.production');

        $this->assertNotEmpty($production, 'No Horizon supervisors are configured for production.');

        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($production as $supervisor => $overrides) {
            $options = array_merge((array) ($defaults[$supervisor] ?? []), (array) $overrides);

            $this->assertArrayHasKey('timeout', $options, "Horizon supervisor [{$supervisor}] does not define a timeout.");

            $timeout = (int) $options['timeout'];

            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                sprintf(
                    'queue.connections.redis.retry_after (%d) must exceed the [%s] supervisor timeout (%d), '
                    .'or a long-running job can be released and re-delivered while still running. '
                    .'Raise REDIS_QUEUE_RETRY_AFTER (and its default in config/queue.php) above the supervisor timeout.',
                    $retryAfter,
                    $supervisor,
                    $timeout,
                ),
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Holds a historic worker still while its staging volume is away.
 *
 * Laravel's `Looping` event is raised before the worker fetches a job, and a
 * listener returning `false` makes the worker pause that iteration instead of
 * taking work ({@see Worker::daemonShouldRun()}). That is the
 * only hook that can stop a job being consumed at all.
 *
 * Why not `Queue::before`: after the before-event the worker checks only
 * `isDeleted()`, so a job released there is pushed back onto the queue *and*
 * still fired — the same job runs twice. Releasing from the before-event trades
 * a failure for a duplicate execution, which is worse.
 *
 * A job already in flight when the volume goes still fails, one per worker.
 * That is the irreducible loss; on 2026-09-05 the reducible loss was the other
 * 365 runs the workers consumed over the following 35 minutes.
 */
final class HistoricStagingQueuePause
{
    /**
     * How often the pause is restated while the volume stays away.
     *
     * The worker loops about once a second, so an unthrottled warning would
     * write ~3,600 identical lines an hour per worker and bury the transition
     * that matters.
     */
    private const RepeatLogSeconds = 300;

    /**
     * Whether this process last saw the volume as away, and when it last said
     * so. Static for the same reason the probe's cache is: the worker resolves
     * a fresh instance rather than holding one.
     */
    private static bool $paused = false;

    private static float $lastLoggedAt = 0.0;

    public function __construct(
        private readonly HistoricStagingReachability $reachability,
        private readonly HistoricProcessingThroughput $throughput,
    ) {}

    /**
     * True when this worker serves historic work and must not take a job now.
     *
     * A worker serving any other queue is never held: the staging volume is not
     * its output, and pausing it would turn a historic-only outage into a
     * site-wide one.
     */
    public function shouldPause(string $queue): bool
    {
        if (! $this->servesHistoricWork($queue)) {
            return false;
        }

        if ($this->reachability->isReachable()) {
            $this->recordResumed($queue);

            return false;
        }

        $this->recordPaused($queue, $this->reachability->unreachableReason());

        return true;
    }

    /**
     * A worker may be given several queues as one comma-separated string, in
     * priority order. Serving any historic queue is enough: the job it takes
     * next could be the historic one.
     */
    private function servesHistoricWork(string $queue): bool
    {
        $served = array_filter(array_map(trim(...), explode(',', $queue)), static fn (string $name): bool => $name !== '');

        if ($served === []) {
            return false;
        }

        try {
            $historic = array_values($this->throughput->configuredQueues());
        } catch (Throwable $exception) {
            /**
             * An unreadable stage configuration must not decide that a worker is
             * non-historic and let it run against a dead mount. Treating it as
             * historic is the fail-safe reading: the reachability probe still
             * has to fail before anything actually pauses.
             */
            Log::warning('Historic queue configuration unreadable; treating worker as historic', [
                'queue' => $queue,
                'error' => $exception->getMessage(),
            ]);

            return true;
        }

        return array_intersect($served, $historic) !== [];
    }

    private function recordPaused(string $queue, ?string $reason): void
    {
        $now = microtime(true);

        if (self::$paused && ($now - self::$lastLoggedAt) < self::RepeatLogSeconds) {
            return;
        }

        self::$paused = true;
        self::$lastLoggedAt = $now;

        Log::warning('Historic staging volume unreachable; holding worker rather than consuming jobs', [
            'queue' => $queue,
            'reason' => $reason,
        ]);
    }

    private function recordResumed(string $queue): void
    {
        if (! self::$paused) {
            return;
        }

        self::$paused = false;
        self::$lastLoggedAt = 0.0;

        Log::info('Historic staging volume reachable again; worker resuming', [
            'queue' => $queue,
        ]);
    }

    /**
     * Discard the remembered pause state.
     *
     * For the test suite only, where one PHP process runs many scenarios that
     * must not inherit the previous one's "already logged" state.
     */
    public static function resetForTesting(): void
    {
        self::$paused = false;
        self::$lastLoggedAt = 0.0;
    }
}

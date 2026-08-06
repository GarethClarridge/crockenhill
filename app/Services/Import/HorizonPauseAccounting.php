<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * §15.2's fourth ingress requirement: "if Horizon is paused globally rather than
 * by affected queue, the budget/report explicitly records the delay imposed on
 * unrelated default/background work."
 *
 * Horizon pauses *supervisors*, not queues. This application's `supervisor-media`
 * serves `default` in the same strict-priority list as the media queues, so there
 * is no pause that stops import work and leaves ordinary background work running
 * — the condition §15.2 guards against holds here structurally, not by an
 * operator's choice of command. Rather than assert that in prose, this class
 * derives it from `config('horizon')` each time, so a future supervisor split
 * that *does* make a queue-granular pause possible is reflected in the report
 * instead of silently contradicting it.
 *
 * Nothing here pauses anything. Pausing is an operator step in §15.1; this is the
 * accounting that step is measured by.
 */
class HorizonPauseAccounting
{
    public function __construct(
        private readonly QueueFactory $queue,
    ) {}

    /**
     * The queues that carry only media-processing and historic-import work.
     * Pausing these delays nothing an import window is not already stopping.
     *
     * `media-processing.queues.default` is deliberately excluded even though it
     * is a media-processing key: it resolves to the application-wide `default`
     * queue, which carries mail, notifications and every other unqueued job.
     * Counting it as "import only" is what would make this report a lie.
     *
     * @return list<string>
     */
    public function importQueues(): array
    {
        /** @var array<string, string> $queues */
        $queues = config('media-processing.queues', []);

        /** @var array<string, array{queue?: string}> $stages */
        $stages = config('media-processing.historic_import.stages', []);

        $names = array_values($queues);

        foreach ($stages as $stage) {
            $names[] = $stage['queue'] ?? '';
        }

        $default = (string) config('queue.connections.redis.queue', 'default');

        return array_values(array_unique(array_filter(
            $names,
            fn (string $name): bool => $name !== '' && $name !== $default,
        )));
    }

    /**
     * Supervisors that must be paused because they serve at least one import
     * queue, mapped to every queue each one serves.
     *
     * @return array<string, list<string>>
     */
    public function supervisorsToPause(): array
    {
        $importQueues = $this->importQueues();
        $paused = [];

        foreach ($this->supervisors() as $name => $supervisor) {
            $queues = $this->queuesFor($supervisor);

            if (array_intersect($queues, $importQueues) !== []) {
                $paused[$name] = $queues;
            }
        }

        return $paused;
    }

    /**
     * Queues that stop when the import supervisors pause, but which carry work
     * the import window has no interest in. These are the delay §15.2 wants
     * recorded.
     *
     * @return list<string>
     */
    public function collateralQueues(): array
    {
        $importQueues = $this->importQueues();
        $collateral = [];

        foreach ($this->supervisorsToPause() as $queues) {
            $collateral = array_merge($collateral, array_diff($queues, $importQueues));
        }

        return array_values(array_unique($collateral));
    }

    /**
     * True when the import queues can be paused without stopping anything else.
     * This is the exact condition §15.2 makes the extra reporting conditional on;
     * when it is false, the closeout report owes a delay figure.
     */
    public function queueGranularPauseIsPossible(): bool
    {
        return $this->collateralQueues() === [];
    }

    /**
     * Pending depth of each collateral queue.
     *
     * A depth read is best-effort by design. If Redis cannot be reached the entry
     * is `null` — "not measured" — because an unmeasurable statistic must not be
     * able to stop an operator blocking ingress, and a 0 in its place would read
     * as "nothing was delayed".
     *
     * @return array<string, int|null>
     */
    public function collateralDepth(): array
    {
        $connections = $this->collateralQueueConnections();
        $depth = [];

        foreach ($connections as $queueName => $connection) {
            try {
                $depth[$queueName] = $this->queue->connection($connection)->size($queueName);
            } catch (Throwable) {
                $depth[$queueName] = null;
            }
        }

        return $depth;
    }

    /**
     * The accounting recorded when a window opens.
     *
     * @return array{
     *     queue_granular_pause_possible: bool,
     *     supervisors_to_pause: array<string, list<string>>,
     *     import_queues: list<string>,
     *     collateral_queues: list<string>,
     *     collateral_depth_at_block: array<string, int|null>
     * }
     */
    public function atBlock(): array
    {
        return [
            'queue_granular_pause_possible' => $this->queueGranularPauseIsPossible(),
            'supervisors_to_pause' => $this->supervisorsToPause(),
            'import_queues' => $this->importQueues(),
            'collateral_queues' => $this->collateralQueues(),
            'collateral_depth_at_block' => $this->collateralDepth(),
        ];
    }

    /**
     * Complete the accounting when the window closes.
     *
     * `collateral_delay_minutes` is the window's own duration: with the pause
     * held for the whole window, that is the delay every unrelated job either
     * waiting at the start or arriving during it could suffer.
     * `collateral_jobs_delayed` is the depth still queued at release — the
     * backlog handed back to the workers, and the figure the closeout report
     * quotes alongside the duration.
     *
     * @param  array<string, mixed>  $atBlock
     * @return array<string, mixed>
     */
    public function atRelease(array $atBlock, int $blockedMinutes): array
    {
        $depthAtRelease = $this->collateralDepth();

        return array_merge($atBlock, [
            'collateral_depth_at_release' => $depthAtRelease,
            'collateral_delay_minutes' => $blockedMinutes,
            'collateral_jobs_delayed' => $this->totalDepth($depthAtRelease),
        ]);
    }

    /**
     * A one-line summary for the operator and the closeout report.
     *
     * @param  array<string, mixed>  $accounting
     */
    public function summarise(array $accounting): string
    {
        if (($accounting['queue_granular_pause_possible'] ?? false) === true) {
            return 'Horizon can be paused by affected queue only; no unrelated background work is delayed.';
        }

        /** @var list<string> $collateral */
        $collateral = $accounting['collateral_queues'] ?? [];
        $queues = implode(', ', $collateral);

        if (! array_key_exists('collateral_delay_minutes', $accounting)) {
            return "Pausing the import supervisors also stops unrelated work on: {$queues}. "
                .'Its delay is recorded against the window and must appear in the closeout report.';
        }

        $minutes = (int) $accounting['collateral_delay_minutes'];
        $jobs = $accounting['collateral_jobs_delayed'];
        $jobsText = $jobs === null ? 'an unmeasured number of' : (string) $jobs;

        return "Unrelated background work on {$queues} was delayed by up to {$minutes} minute(s); "
            ."{$jobsText} job(s) were still queued when ingress reopened.";
    }

    /**
     * Queue name to the connection its paused supervisor runs on. Supervisors
     * declare their own connection, so the depth is read from the same place the
     * paused worker would have read it.
     *
     * @return array<string, string>
     */
    private function collateralQueueConnections(): array
    {
        $importQueues = $this->importQueues();
        $connections = [];

        foreach ($this->supervisors() as $name => $supervisor) {
            $queues = $this->queuesFor($supervisor);

            if (array_intersect($queues, $importQueues) === []) {
                continue;
            }

            foreach (array_diff($queues, $importQueues) as $queueName) {
                $connections[$queueName] ??= (string) ($supervisor['connection'] ?? config('queue.default'));
            }
        }

        return $connections;
    }

    /**
     * Supervisor definitions for the running environment, with the environment
     * overrides merged over the defaults the way Horizon itself resolves them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function supervisors(): array
    {
        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = config('horizon.defaults', []);

        /** @var array<string, array<string, mixed>> $environment */
        $environment = config('horizon.environments.'.app()->environment(), []);

        $supervisors = [];

        foreach ($environment as $name => $overrides) {
            $supervisors[$name] = array_merge($defaults[$name] ?? [], $overrides);
        }

        return $supervisors === [] ? $defaults : $supervisors;
    }

    /**
     * @param  array<string, mixed>  $supervisor
     * @return list<string>
     */
    private function queuesFor(array $supervisor): array
    {
        $queues = $supervisor['queue'] ?? [];

        return array_values(array_map(strval(...), is_array($queues) ? $queues : [$queues]));
    }

    /**
     * Null when no collateral queue could be measured at all; an unmeasured
     * backlog must not be reported as an empty one.
     *
     * @param  array<string, int|null>  $depth
     */
    private function totalDepth(array $depth): ?int
    {
        $measured = array_filter($depth, fn (?int $size): bool => $size !== null);

        return $measured === [] && $depth !== [] ? null : array_sum($measured);
    }
}

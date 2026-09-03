<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Answers one question the database cannot: is any historic work actually in
 * flight right now?
 *
 * A run's `status` records what the pipeline last believed, not what the queue
 * is doing. When an activation failure is raised before a job fires, the run
 * keeps reading `pending` or `processing` for ever while nothing is queued —
 * which is indistinguishable from healthy queuing if you only read the database.
 * That is the wedge that stranded five runs on 2026-09-03 and left pass 1's
 * failures unreachable before it.
 *
 * The signal is deliberately a plain count rather than a staleness threshold: a
 * queue with zero waiting, delayed and reserved jobs is not "probably idle", it
 * is definitively doing nothing, and no timing heuristic has to be tuned.
 */
class HistoricPassInFlightProbe
{
    public function __construct(
        private readonly HistoricProcessingThroughput $throughput,
        private readonly QueueFactory $queue,
    ) {}

    /**
     * Waiting + delayed + reserved for each distinct historic queue.
     *
     * `Queue::size()` sums all three on the Redis driver, which is what makes a
     * zero here mean "nothing reserved by a worker either" rather than merely
     * "nothing waiting to be picked up".
     *
     * @return array<string, int>
     */
    public function depthsByQueue(): array
    {
        $connection = $this->queue->connection();
        $depths = [];

        foreach (array_unique($this->throughput->configuredQueues()) as $queue) {
            $depths[$queue] = (int) $connection->size($queue);
        }

        return $depths;
    }

    public function inFlightCount(): int
    {
        return array_sum($this->depthsByQueue());
    }

    /**
     * True when historic runs are still open but no historic queue holds any
     * work — the pass cannot make progress and never will without intervention.
     *
     * @param  list<int>|null  $processingLogIds  Restrict to one pass's runs; null considers every historic run.
     */
    public function isWedged(?array $processingLogIds = null): bool
    {
        return $this->openRunCount($processingLogIds) > 0 && $this->inFlightCount() === 0;
    }

    /** @param  list<int>|null  $processingLogIds */
    public function openRunCount(?array $processingLogIds = null): int
    {
        return MediaProcessingLog::query()
            ->whereNotNull('historic_import_operation_id')
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ])
            ->when($processingLogIds !== null, fn ($query) => $query->whereIn('id', $processingLogIds))
            ->count();
    }
}

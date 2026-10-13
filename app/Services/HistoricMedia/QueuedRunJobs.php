<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Redis;

/**
 * The jobs on the worker queues that name a processing run: waiting, delayed or reserved.
 *
 * A run whose chain was pulled from the queues still reads `processing`; before anything treats
 * it as settled, nothing anywhere may still be about to work on it.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class QueuedRunJobs
{
    public function __construct(private readonly HistoricRerunWatch $watch) {}

    /**
     * The processing runs a job payload names, its chained jobs included.
     *
     * @param  mixed  $payload  A decoded queue or `failed_jobs` payload
     * @return list<int>
     */
    public static function runsNamedBy(mixed $payload): array
    {
        $command = is_array($payload) ? ($payload['data']['command'] ?? null) : null;

        if (! is_string($command) || preg_match_all('/"'.preg_quote(MediaProcessingLog::class, '/').'";s:2:"id";i:(\d+);/', $command, $matches) === 0) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * One line per queued job naming the run: where it waits and what it is.
     *
     * @return list<string>
     */
    public function naming(int $runId): array
    {
        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $found = [];

        foreach ($this->watch->queues() as $queue) {
            $lists = [
                'waiting' => $redis->lrange("queues:{$queue}", 0, -1),
                'delayed' => $redis->zrange("queues:{$queue}:delayed", 0, -1),
                'reserved' => $redis->zrange("queues:{$queue}:reserved", 0, -1),
            ];

            foreach ($lists as $state => $payloads) {
                foreach ((array) $payloads as $payload) {
                    $decoded = json_decode((string) $payload, true);

                    if (in_array($runId, self::runsNamedBy($decoded), true)) {
                        $found[] = sprintf('%s %s on %s', $state, $decoded['displayName'] ?? 'unknown job', $queue);
                    }
                }
            }
        }

        return $found;
    }
}

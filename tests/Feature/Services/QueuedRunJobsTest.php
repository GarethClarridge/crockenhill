<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\PromoteHistoricAssets;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunWatch;
use App\Services\HistoricMedia\QueuedRunJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/** Runs PHPUnit's own Redis databases (phpunit.xml), never a running app's. */
class QueuedRunJobsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_finds_a_runs_jobs_whether_waiting_delayed_or_reserved(): void
    {
        $connection = (string) config('queue.connections.redis.connection');

        try {
            Redis::connection($connection)->ping();
        } catch (Throwable $exception) {
            $this->markTestSkipped("Redis connection [{$connection}] is unreachable: {$exception->getMessage()}");
        }

        $queue = app(HistoricRerunWatch::class)->queues()[0];
        $redis = Redis::connection($connection);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $other = MediaProcessingLog::factory()->livestream()->completed()->create();

        try {
            $redis->rpush("queues:{$queue}", [$this->payload($run)]);
            $redis->zadd("queues:{$queue}:delayed", [$this->payload($run) => 1]);
            $redis->zadd("queues:{$queue}:reserved", [$this->payload($other) => 1]);

            $queuedJobs = app(QueuedRunJobs::class);

            self::assertSame([
                "waiting App\\Jobs\\PromoteHistoricAssets on {$queue}",
                "delayed App\\Jobs\\PromoteHistoricAssets on {$queue}",
            ], $queuedJobs->naming($run->id));
            self::assertSame(["reserved App\\Jobs\\PromoteHistoricAssets on {$queue}"], $queuedJobs->naming($other->id));
            self::assertSame([], $queuedJobs->naming($other->id + 1000));
        } finally {
            $redis->del("queues:{$queue}", "queues:{$queue}:delayed", "queues:{$queue}:reserved");
        }
    }

    private function payload(MediaProcessingLog $run): string
    {
        $job = new PromoteHistoricAssets($run);

        return (string) json_encode(['displayName' => $job::class, 'data' => ['commandName' => $job::class, 'command' => serialize($job)]]);
    }
}

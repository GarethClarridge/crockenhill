<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Guards the invariant that flushing the Redis cache cannot destroy queued work.
 *
 * Laravel's Redis cache store implements `flush()` as `FLUSHDB`, which ignores
 * CACHE_PREFIX and erases the whole Redis database — `queues:*` lists, their
 * reserved and delayed sorted sets, and Horizon's data with them. So the only
 * thing that actually separates the cache from the queue is the Redis *database
 * number*, not the key prefix.
 *
 * This is not hypothetical: `DuskTestCase::setUp()` flushes the served app's
 * cache store once per test, and on 2026-09-07 that destroyed 30 of 60 paid
 * re-analysis jobs mid-drain. The tell was indistinguishable from success —
 * pending, reserved and delayed all zero, `failed_jobs` unchanged.
 */
class CacheQueueRedisIsolationTest extends TestCase
{
    /**
     * The configuration guarantee, enforceable anywhere — CI has no Redis.
     */
    #[Test]
    public function the_redis_cache_store_uses_a_different_database_than_the_queue(): void
    {
        $cacheDatabase = $this->redisDatabaseFor((string) config('cache.stores.redis.connection'));
        $queueDatabase = $this->redisDatabaseFor((string) config('queue.connections.redis.connection'));

        $this->assertNotSame(
            $queueDatabase,
            $cacheDatabase,
            'The Redis cache store and the Redis queue resolve to the same Redis database '
            ."({$cacheDatabase}). Cache::store('redis')->flush() issues FLUSHDB, so any cache "
            .'clear — including the one DuskTestCase runs before every test — erases every '
            .'queued job. Give the cache its own connection and database in config/database.php.',
        );
    }

    /**
     * Horizon stores its own job records and metrics in Redis, and a cache
     * flush on the same database takes those with it.
     */
    #[Test]
    public function the_redis_cache_store_uses_a_different_database_than_horizon(): void
    {
        $cacheDatabase = $this->redisDatabaseFor((string) config('cache.stores.redis.connection'));
        $horizonDatabase = $this->redisDatabaseFor((string) config('horizon.use'));

        $this->assertNotSame(
            $horizonDatabase,
            $cacheDatabase,
            'The Redis cache store and Horizon resolve to the same Redis database '
            ."({$cacheDatabase}), so a cache flush erases Horizon's job records and metrics.",
        );
    }

    /**
     * The behaviour the configuration is there to buy.
     *
     * The precondition assertion is load-bearing: it runs *before* the flush, so
     * if the isolation is ever removed this test fails without erasing anything.
     * That is the difference between proving the guarantee and re-staging the
     * incident against whatever the local queue happens to be holding.
     */
    #[Test]
    public function a_queued_sentinel_survives_a_cache_flush(): void
    {
        $cacheConnection = (string) config('cache.stores.redis.connection');
        $queueConnection = (string) config('queue.connections.redis.connection');

        $this->assertNotSame(
            $this->redisDatabaseFor($queueConnection),
            $this->redisDatabaseFor($cacheConnection),
            'Refusing to flush: the cache and queue share a Redis database, so this test '
            .'would destroy live queued work rather than measure anything.',
        );

        $this->skipWithoutRedis($cacheConnection);
        $this->skipWithoutRedis($queueConnection);

        $sentinelKey = 'queues:'.$this->sentinelName();

        try {
            Redis::connection($queueConnection)->rpush($sentinelKey, ['{"sentinel":true}']);

            $store = Cache::store('redis');
            $this->assertInstanceOf(RedisStore::class, $store->getStore());

            $store->put('isolation-probe', 'present', 60);
            $store->flush();

            $this->assertNull($store->get('isolation-probe'), 'The cache flush did not clear the cache.');
            $this->assertSame(
                1,
                (int) Redis::connection($queueConnection)->llen($sentinelKey),
                'A cache flush destroyed the queued sentinel: the cache store still reaches the queue database.',
            );
        } finally {
            Redis::connection($queueConnection)->del($sentinelKey);
        }
    }

    private function redisDatabaseFor(string $connection): int
    {
        $database = config("database.redis.{$connection}.database");

        $this->assertNotNull(
            $database,
            "The Redis connection [{$connection}] is not defined in config/database.php.",
        );

        return (int) $database;
    }

    private function sentinelName(): string
    {
        return 'isolation-sentinel-'.getmypid().'-'.bin2hex(random_bytes(4));
    }

    private function skipWithoutRedis(string $connection): void
    {
        try {
            Redis::connection($connection)->ping();
        } catch (Throwable $exception) {
            $this->markTestSkipped("Redis connection [{$connection}] is unreachable: {$exception->getMessage()}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonPresenterCache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPresenterCacheTest extends TestCase
{
    private SermonPresenterCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new SermonPresenterCache;
    }

    #[Test]
    public function it_computes_a_value_once_and_memoizes_it(): void
    {
        $sermon = Sermon::factory()->make(['id' => 1, 'updated_at' => now()]);
        $calls = 0;

        $compute = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $this->cache->remember($sermon, 'thing', 'memoized', $compute));
        $this->assertSame('value', $this->cache->remember($sermon, 'thing', 'memoized', $compute));
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function it_caches_null_as_a_legitimate_result(): void
    {
        $sermon = Sermon::factory()->make(['id' => 2, 'updated_at' => now()]);
        $calls = 0;

        $compute = function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        $this->assertNull($this->cache->remember($sermon, 'maybe', 'memoizedUrls', $compute));
        $this->assertNull($this->cache->remember($sermon, 'maybe', 'memoizedUrls', $compute));
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function it_isolates_distinct_sermon_ids(): void
    {
        $first = Sermon::factory()->make(['id' => 3, 'updated_at' => now()]);
        $second = Sermon::factory()->make(['id' => 99, 'updated_at' => now()]);

        $this->assertSame('first', $this->cache->remember($first, 'thing', 'memoized', fn (): string => 'first'));
        $this->assertSame('second', $this->cache->remember($second, 'thing', 'memoized', fn (): string => 'second'));
    }

    #[Test]
    public function it_captures_the_sermon_key_once_per_id(): void
    {
        // The combined id+timestamp key is memoized per id on first sight, so a
        // later object sharing the id reuses that key even if its timestamp moved.
        $first = Sermon::factory()->make(['id' => 3, 'updated_at' => now()]);
        $this->assertSame('first', $this->cache->remember($first, 'thing', 'memoized', fn (): string => 'first'));

        $sameId = Sermon::factory()->make(['id' => 3, 'updated_at' => now()->addDay()]);
        $this->assertSame('first', $this->cache->remember($sameId, 'thing', 'memoized', fn (): string => 'second'));
    }

    #[Test]
    public function it_isolates_distinct_types(): void
    {
        $sermon = Sermon::factory()->make(['id' => 4, 'updated_at' => now()]);

        $this->assertSame('audio', $this->cache->remember($sermon, 'audio', 'memoizedUrls', fn (): string => 'audio'));
        $this->assertSame('video', $this->cache->remember($sermon, 'video', 'memoizedUrls', fn (): string => 'video'));
    }

    #[Test]
    public function it_memoizes_collection_results_by_key(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return [1 => ['a' => 'b']];
        };

        $this->assertSame([1 => ['a' => 'b']], $this->cache->rememberCollection('col_x', $compute));
        $this->assertSame([1 => ['a' => 'b']], $this->cache->rememberCollection('col_x', $compute));
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function it_clears_all_stores(): void
    {
        $sermon = Sermon::factory()->make(['id' => 5, 'updated_at' => now()]);
        $this->assertSame('first', $this->cache->remember($sermon, 'thing', 'memoized', fn (): string => 'first'));

        $this->cache->clear();

        $this->assertSame('second', $this->cache->remember($sermon, 'thing', 'memoized', fn (): string => 'second'));
    }
}

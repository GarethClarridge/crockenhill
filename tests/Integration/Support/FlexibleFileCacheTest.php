<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlexibleFileCacheTest extends TestCase
{
    #[Test]
    public function file_cache_executes_the_deferred_refresh_after_the_fresh_window(): void
    {
        $store = Cache::store('file');
        $key = 'flexible-file-test-'.Str::uuid();

        try {
            $this->assertSame('initial', $store->flexible($key, [300, 86400], fn (): string => 'initial'));

            $this->travel(301)->seconds();

            $this->assertSame('initial', $store->flexible($key, [300, 86400], fn (): string => 'refreshed'));

            \Illuminate\Support\defer()->invoke();

            $this->assertSame('refreshed', $store->get($key));
        } finally {
            $store->forget($key);
            $store->forget(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key);
        }
    }
}

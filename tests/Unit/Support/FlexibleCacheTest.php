<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FlexibleCache;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlexibleCacheTest extends TestCase
{
    #[Test]
    public function it_forgets_the_value_and_flexible_cache_timestamp(): void
    {
        Cache::flexible('example', [300, 86400], fn (): string => 'initial');

        FlexibleCache::forget('example');
        Cache::put('example', 'orphaned-value');

        $this->assertSame(
            'recomputed',
            Cache::flexible('example', [300, 86400], fn (): string => 'recomputed'),
        );
    }
}

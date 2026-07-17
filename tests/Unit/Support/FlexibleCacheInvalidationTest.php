<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public caches invalidate flexible values with a plain Cache::forget()
 * on the value key, deliberately not touching Laravel's framework-private
 * `illuminate:cache:flexible:created:` bookkeeping key (issue O50). That is
 * safe because Cache::flexible() recomputes whenever the value itself is
 * missing, whatever the bookkeeping key holds — and a deferred stale refresh
 * re-runs the callback (producing fresh data) rather than re-storing the old
 * value. This test pins that framework contract: if it fails after a
 * framework upgrade, the invalidation strategy needs revisiting.
 */
class FlexibleCacheInvalidationTest extends TestCase
{
    #[Test]
    public function forgetting_the_value_key_alone_forces_a_recompute(): void
    {
        Cache::flexible('example', [300, 86400], fn (): string => 'initial');

        Cache::forget('example');

        $this->assertSame(
            'recomputed',
            Cache::flexible('example', [300, 86400], fn (): string => 'recomputed'),
        );
    }
}

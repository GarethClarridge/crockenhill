<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Routing\Middleware\ThrottleRequests;

trait BypassesThrottleRequests
{
    protected function withoutThrottleRequests(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}

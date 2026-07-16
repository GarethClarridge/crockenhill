<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class FlexibleCache
{
    public static function forget(string $key): void
    {
        Cache::forget($key);
        Cache::forget("illuminate:cache:flexible:created:{$key}");
    }
}

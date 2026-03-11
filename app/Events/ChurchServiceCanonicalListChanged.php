<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChurchServiceCanonicalListChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    public function __construct(
        public readonly int $churchServiceId,
        public readonly string $source,
        public readonly array $changes,
    ) {}
}

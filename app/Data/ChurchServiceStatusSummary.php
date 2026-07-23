<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChurchServiceRollupStatus;

final readonly class ChurchServiceStatusSummary
{
    public function __construct(
        public ChurchServiceRollupStatus $status,
        public string $explanation,
        public ?string $actionLabel,
        public ?string $actionUrl,
        public ?string $attentionTarget,
    ) {}
}

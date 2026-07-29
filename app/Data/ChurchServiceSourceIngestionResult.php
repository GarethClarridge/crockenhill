<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchServiceSourceRecord;

readonly class ChurchServiceSourceIngestionResult
{
    public function __construct(
        public ChurchServiceSourceRecord $sourceRecord,
        public bool $wasCreated,
    ) {}
}

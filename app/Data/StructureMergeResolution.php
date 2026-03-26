<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;

readonly class StructureMergeResolution
{
    public function __construct(
        public ChurchService $churchService,
        public string $resolution,
        public bool $applied,
        public string $reason = '',
    ) {}
}

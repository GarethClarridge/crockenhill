<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;

readonly class ChurchServiceEvidenceReviewResult
{
    public function __construct(
        public ChurchService $churchService,
        public bool $applied,
        public string $reason,
    ) {}
}

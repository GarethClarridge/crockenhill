<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;
use App\Models\User;

readonly class ChurchServiceConvergenceImportPlan
{
    /**
     * @param  'already_present'|'apply'|'blocked_difference'|'conflict'  $classification
     * @param  array<string, mixed>  $servicePayload
     */
    public function __construct(
        public string $classification,
        public string $reason,
        public string $planHash,
        public string $bundleHash,
        public ChurchService $churchService,
        public ?User $reviewer,
        public array $servicePayload,
    ) {}
}

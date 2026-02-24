<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProcessingLogCollection;
use Carbon\Carbon;

interface ProcessingLogContract
{
    public function getProcessingLogs(string $processingId, int $limit = 50): ProcessingLogCollection;

    public function getLogsSince(string $processingId, Carbon $since): ProcessingLogCollection;

    public function getLogsByStep(string $processingId, string $step): ProcessingLogCollection;

    /**
     * @return array<string, mixed>|null
     */
    public function getPerformanceMetrics(string $processingId): ?array;
}

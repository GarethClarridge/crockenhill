<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\Carbon;

readonly class ProcessingLogEntry
{
    /**
     * @param  array<string, mixed>|null  $metrics
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public string $step,
        public string $level,
        public string $message,
        public Carbon $timestamp,
        public ?array $metrics = null,
        public ?string $errorMessage = null,
        public ?array $context = null,
        public ?float $executionTime = null,
        public ?int $memoryUsage = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'level' => $this->level,
            'message' => $this->message,
            'timestamp' => $this->timestamp->toISOString(),
            'metrics' => $this->metrics,
            'error_message' => $this->errorMessage,
            'context' => $this->context,
            'execution_time' => $this->executionTime,
            'memory_usage' => $this->memoryUsage,
        ];
    }
}

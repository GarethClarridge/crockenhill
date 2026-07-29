<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChurchServiceSource;
use Illuminate\Support\Carbon;

readonly class ChurchServiceSourceRevision
{
    /**
     * @param  list<array<string, mixed>>  $assertions
     * @param  array<string, mixed>  $processingFingerprint
     * @param  array<string, mixed>|null  $serviceContent
     */
    public function __construct(
        public ChurchServiceSource $source,
        public string $sourceKey,
        public string $inputHash,
        public array $assertions,
        public array $processingFingerprint,
        public ?array $serviceContent = null,
        public ?string $batchHash = null,
        public bool $payloadComplete = true,
        public ?Carbon $capturedAt = null,
        public ?int $createdByUserId = null,
    ) {}
}

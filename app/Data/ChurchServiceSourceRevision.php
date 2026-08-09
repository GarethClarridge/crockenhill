<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChurchServiceSource;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Support\Carbon;

readonly class ChurchServiceSourceRevision
{
    public string $sourceKey;

    public ?string $supersedesSourceKey;

    /**
     * @param  list<array<string, mixed>>  $assertions
     * @param  array<string, mixed>  $processingFingerprint
     * @param  array<string, mixed>|null  $serviceContent
     */
    public function __construct(
        public ChurchServiceSource $source,
        string $sourceKey,
        public string $inputHash,
        public array $assertions,
        public array $processingFingerprint,
        ?string $supersedesSourceKey = null,
        public ?array $serviceContent = null,
        public ?string $batchHash = null,
        public bool $payloadComplete = true,
        public ?Carbon $capturedAt = null,
        public ?int $createdByUserId = null,
    ) {
        $this->sourceKey = ChurchServiceSourceKey::canonical($sourceKey);
        $this->supersedesSourceKey = $supersedesSourceKey === null
            ? null
            : ChurchServiceSourceKey::canonical($supersedesSourceKey);
    }
}

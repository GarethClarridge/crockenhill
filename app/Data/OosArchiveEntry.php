<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

readonly class OosArchiveEntry
{
    /**
     * @param  list<string>  $servicesPresent
     * @param  array<string, int>  $itemLineCounts
     * @param  array<string, list<string>>  $itemLines
     * @param  list<string>  $flags
     */
    public function __construct(
        public int $index,
        public string $heading,
        public string $subject,
        public string $bodyPlain,
        public ?string $headingDate,
        public ?string $correctedDate,
        public ?string $groundTruthDate,
        public string $labelQuality,
        public array $servicesPresent,
        public array $itemLineCounts,
        public array $itemLines,
        public array $flags,
        public string $syntheticMessageId,
        public string $inputHash,
        public ?CarbonImmutable $syntheticReceivedAt,
    ) {}

    public function isImportableCohort(): bool
    {
        return $this->labelQuality === 'full' && $this->groundTruthDate !== null;
    }
}

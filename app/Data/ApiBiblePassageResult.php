<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Value object returned by ApiBibleClient for a resolved passage.
 */
final readonly class ApiBiblePassageResult
{
    public function __construct(
        public string $passageId,
        public string $displayReference,
        public string $htmlContent,
        public string $copyright,
        public ?string $fumsToken,
    ) {}
}

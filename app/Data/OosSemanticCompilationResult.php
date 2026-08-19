<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosSemanticCompilationResult
{
    /** @param array<string, mixed> $riskSignals */
    public function __construct(
        public OosEmailItemExtractionResult $extraction,
        public array $riskSignals,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosSemanticParserOutcome
{
    /**
     * @param  list<array<string, mixed>>  $attempts
     * @param  array<string, mixed>  $riskSignals
     */
    public function __construct(
        public OosEmailItemExtractionResult $extraction,
        public array $attempts,
        public array $riskSignals,
    ) {}
}

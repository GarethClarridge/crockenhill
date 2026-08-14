<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailItemExtractionResult;

interface AdjudicatingOosEmailItemExtractor extends CorrectiveOosEmailItemExtractor
{
    /** @param list<string> $disagreementCategories */
    public function adjudicate(
        string $subject,
        string $body,
        string $receivedDate,
        OosEmailItemExtractionResult $initialExtraction,
        OosEmailItemExtractionResult $correctedExtraction,
        array $disagreementCategories,
    ): OosEmailItemExtractionResult;
}

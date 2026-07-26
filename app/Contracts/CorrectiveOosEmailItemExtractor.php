<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailItemExtractionResult;

interface CorrectiveOosEmailItemExtractor extends OosEmailItemExtractor
{
    /**
     * @param  list<string>  $validationFailures
     */
    public function correct(
        string $subject,
        string $body,
        string $receivedDate,
        OosEmailItemExtractionResult $previousExtraction,
        array $validationFailures,
    ): OosEmailItemExtractionResult;
}

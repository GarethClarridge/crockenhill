<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailItemExtractionResult;

interface OosEmailItemExtractor
{
    public function extract(string $subject, string $body): OosEmailItemExtractionResult;
}

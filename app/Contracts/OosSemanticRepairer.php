<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationPatch;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;

interface OosSemanticRepairer
{
    /** @param list<OosSemanticFinding> $findings */
    public function repair(
        OosEmailSourceDocument $source,
        OosSemanticAnnotationResult $annotations,
        array $findings,
    ): OosSemanticAnnotationPatch;
}

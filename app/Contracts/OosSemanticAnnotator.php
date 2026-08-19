<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;

interface OosSemanticAnnotator
{
    public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult;
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\OosSemanticAnnotator;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;

class FakeOosSemanticAnnotator implements OosSemanticAnnotator
{
    public int $calls = 0;

    public function __construct(private readonly OosSemanticAnnotationResult $result) {}

    public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
    {
        $this->calls++;

        return $this->result;
    }
}

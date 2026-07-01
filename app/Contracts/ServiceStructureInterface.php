<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;

interface ServiceStructureInterface
{
    /**
     * Detect the typed, timed structure of a service from its full transcript
     * and the planned order of service.
     *
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $oosItems
     *
     * @throws \RuntimeException When detection fails or the response is invalid
     */
    public function detect(
        ChurchServiceTranscript $transcript,
        array $oosItems,
        ?string $processingId = null,
    ): ServiceStructure;
}

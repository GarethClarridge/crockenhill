<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Jobs\DetectServiceStructure;
use App\Services\Media\Audio\AudioTimeline;

interface ServiceStructureInterface
{
    /**
     * Detect the typed, timed structure of a service from its full transcript
     * and the planned order of service.
     *
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $oosItems
     * @param  list<string>  $feedback  Corrections from a previous attempt this run, surfaced to the detector so a retry can address them
     * @param  AudioTimeline|null  $audioTimeline  Where the audio holds music, independent of the transcript. The pipeline
     *                                             always supplies one ({@see DetectServiceStructure} refuses a
     *                                             run without); null only from evaluation tooling over older runs
     * @param  string|null  $model  Explicit model for one ensemble slot; null uses the configured single-call model
     *
     * @throws \RuntimeException When detection fails or the response is invalid
     */
    public function detect(
        ChurchServiceTranscript $transcript,
        array $oosItems,
        ?string $processingId = null,
        array $feedback = [],
        ?AudioTimeline $audioTimeline = null,
        ?string $model = null,
    ): ServiceStructure;
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\Media\Audio\ServiceArtifactStorage;

/** Explicit empty decoded windows for tests of the retained whole-cue fallback. */
trait BanksNoWordOutputEdges
{
    private function bankNoWordOutputEdges(MediaProcessingLog $log): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        foreach ($cues as $cue) {
            foreach ([$cue['start'], ($cue['start'] + $cue['end']) / 2, $cue['end']] as $edge) {
                $window = $evidence->window($cues, $edge, $log->duration);
                if ($window !== null) {
                    $identity = $evidence->identity($log, $window);
                    app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => [], 'compute_seconds' => 0.0]);
                }
            }
        }
        $log->refresh();
    }
}

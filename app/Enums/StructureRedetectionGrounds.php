<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a settled historic run's structure may be re-detected.
 *
 * Re-detection re-opens a finished run and re-cuts its media, so it is never a general
 * facility: each caller names its grounds, and the orchestrator checks the evidence those
 * grounds need on top of the guards every re-detection shares.
 */
enum StructureRedetectionGrounds: string
{
    /** The recovery replay put words back into the transcript the structure was drawn from. */
    case RecoveredTranscript = 'recovered_transcript';

    /**
     * The historic corpus re-run (plan §4.0): the detection code changed, and the run is a
     * member of a snapshotted batch frozen on the commit that changed it.
     */
    case CorpusRerun = 'corpus_rerun';
}

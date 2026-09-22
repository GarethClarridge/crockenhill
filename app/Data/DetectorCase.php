<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DetectorCaseBasis;
use App\Enums\DetectorCaseTruth;

/**
 * One adjudicated case in §4.3a H3's case book, as authored.
 *
 * The subject is always a run, because the run is the only id every surface
 * shares. A section or sermon narrows it, and the freezer proves each belongs to
 * that run: sermon and run ids share one numeric range, and the catalogue once
 * named sermons as runs, pointing seven closing-prayer fixtures at the wrong
 * services.
 */
final readonly class DetectorCase
{
    public function __construct(
        public string $caseId,
        public string $detectorId,
        public ?string $reference,
        public int $run,
        public ?int $section,
        public ?int $sermon,
        public DetectorCaseTruth $truth,
        public DetectorCaseBasis $basis,
        public string $evidence,
        public bool $informedFix,
        public ?string $note = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;

/**
 * What one run's repetition recovery did, reported in the terms a reviewer needs
 * rather than as a single success flag.
 *
 * The three outcomes are kept apart on purpose. A block that was recovered, a
 * block whose audio could not be reached, and a block whose retry looped again
 * all leave the run in different states, and only the first is a repair. Rolling
 * them into "processed" is how a pass reports progress it did not make.
 */
final readonly class TranscriptRepetitionRecoveryResult
{
    public function __construct(
        public ChurchServiceTranscript $transcript,
        public int $blocks,
        public int $recovered,
        public int $unavailable,
        public int $stillLooping,
        public int $loopedWordsRemoved,
        public int $wordsRecovered,
    ) {}

    public function changedAnything(): bool
    {
        return $this->recovered > 0 || $this->stillLooping > 0;
    }
}

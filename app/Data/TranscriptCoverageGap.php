<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Media\Audio\ServiceTranscriptCoverageScreen;

/**
 * A stretch where the recording carries sustained sound that the transcript
 * does not account for.
 *
 * The mirror image of {@see SuspectTranscriptBlock}: a block records text the
 * audio cannot explain, a gap records audio the text does not. Neither is a
 * verdict that speech was lost — the sound could be music, a congregation
 * moving, or a microphone left open over a quiet room — so a gap is a candidate
 * for review, never a proven defect.
 *
 * Produced by {@see ServiceTranscriptCoverageScreen}, which raises no holds.
 *
 * @phpstan-type TranscriptCoverageGapShape array{
 *     start: float,
 *     end: float,
 *     seconds: float,
 *     sounded_seconds: float,
 *     words: int,
 *     words_per_minute: float,
 * }
 */
final readonly class TranscriptCoverageGap
{
    public function __construct(
        public float $start,
        public float $end,
        public float $soundedSeconds,
        public int $words,
    ) {}

    public function seconds(): float
    {
        return $this->end - $this->start;
    }

    /**
     * Words per minute *of sound*, not of elapsed time.
     *
     * Dividing by elapsed time would flatter a stretch that is mostly silence:
     * the question is how much was said while something was audible.
     */
    public function wordsPerMinute(): float
    {
        if ($this->soundedSeconds <= 0.0) {
            return 0.0;
        }

        return $this->words / $this->soundedSeconds * 60;
    }

    /**
     * @return TranscriptCoverageGapShape
     */
    public function toArray(): array
    {
        return [
            'start' => round($this->start, 2),
            'end' => round($this->end, 2),
            'seconds' => round($this->seconds(), 2),
            'sounded_seconds' => round($this->soundedSeconds, 2),
            'words' => $this->words,
            'words_per_minute' => round($this->wordsPerMinute(), 1),
        ];
    }
}

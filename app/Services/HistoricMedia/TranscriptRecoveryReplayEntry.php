<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ChurchServiceTranscript;

/**
 * One run's replay verdict, and the evidence behind it.
 *
 * The blind seconds and word counts are the verdict, not decoration: a replay
 * that recovers nothing has either found a retry that genuinely loops throughout
 * — a real and common outcome — or failed to locate the retry at all, and only
 * the window and artifact counts separate those two.
 */
final readonly class TranscriptRecoveryReplayEntry
{
    /**
     * @param  list<array{start: float, end: float, reason: string}>  $windowsAfter
     */
    public function __construct(
        public int $logId,
        public string $processingId,
        public string $disposition,
        public ?string $reason = null,
        public int $detectedWindows = 0,
        public int $retriesFound = 0,
        public int $windowCountBefore = 0,
        public int $windowCountAfter = 0,
        public float $blindSecondsBefore = 0.0,
        public float $blindSecondsAfter = 0.0,
        public int $wordsBefore = 0,
        public int $wordsAfter = 0,
        public array $windowsAfter = [],
        public ?ChurchServiceTranscript $recovered = null,
    ) {}

    public function isReplayable(): bool
    {
        return $this->disposition === HistoricTranscriptRecoveryReplay::DISPOSITION_REPLAYABLE;
    }

    public function blindSecondsRecovered(): float
    {
        return $this->blindSecondsBefore - $this->blindSecondsAfter;
    }

    public function wordsRecovered(): int
    {
        return $this->wordsAfter - $this->wordsBefore;
    }

    /**
     * How the replay moved this run, for reporting.
     *
     * A run whose blind time is unchanged is not a failure — its retry loops
     * from end to end, so the window stays blind, which is the correct answer.
     * A run that ends up slightly blinder is precision: a residual loop is
     * recorded at its own bounds instead of being absorbed into one larger
     * window, so one window can become two spanning a little more.
     */
    public function outcome(): string
    {
        if (! $this->isReplayable()) {
            return $this->disposition;
        }

        if ($this->blindSecondsAfter <= 0.0 && $this->blindSecondsBefore > 0.0) {
            return 'fully recovered';
        }

        $moved = $this->blindSecondsRecovered();

        return match (true) {
            $moved > 0.5 => 'partly recovered',
            $moved < -0.5 => 'slightly blinder',
            default => 'unchanged',
        };
    }

    public function with(string $disposition, ?string $reason = null): self
    {
        return new self(
            logId: $this->logId,
            processingId: $this->processingId,
            disposition: $disposition,
            reason: $reason,
            detectedWindows: $this->detectedWindows,
            retriesFound: $this->retriesFound,
            windowCountBefore: $this->windowCountBefore,
            windowCountAfter: $this->windowCountAfter,
            blindSecondsBefore: $this->blindSecondsBefore,
            blindSecondsAfter: $this->blindSecondsAfter,
            wordsBefore: $this->wordsBefore,
            wordsAfter: $this->wordsAfter,
            windowsAfter: $this->windowsAfter,
            recovered: $this->recovered,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;

/**
 * One stretch of a full-service transcript whose text cannot be trusted as
 * evidence of what was said.
 *
 * Distinct from an unobservable window: a window records that the recording was
 * *looked at and yielded nothing*, and is produced by
 * {@see ServiceTranscriptPathologyDetector} driving re-transcription. A block
 * records that the transcript *claims* text which the text itself contradicts —
 * a phrase repeated verbatim past the point speech could have produced it,
 * words arriving faster than a person can say them, or one short cue emitted on
 * each 30-second decoder boundary between ordinary speech. The audio behind a block has
 * not been looked at, so a block is a reason to hold, never a verdict that the
 * material is lost.
 *
 * @phpstan-type SuspectTranscriptBlockShape array{
 *     start: float,
 *     end: float,
 *     reason: string,
 *     words: int,
 *     words_per_minute: float|null,
 *     phrase?: string,
 *     repeats?: int,
 * }
 */
final readonly class SuspectTranscriptBlock
{
    /** A phrase repeated verbatim, back to back, past the point speech explains it. */
    public const REASON_REPEATED_PHRASE = 'repeated_phrase_loop';

    /** Sustained words-per-minute beyond what a speaker can produce. */
    public const REASON_IMPLAUSIBLE_DENSITY = 'implausible_word_density';

    /** Short cues on consecutive decoder boundaries inside otherwise dense speech. */
    public const REASON_SPARSE_CADENCE = 'sparse_thirty_second_cadence';

    public function __construct(
        public float $start,
        public float $end,
        public string $reason,
        public int $words,
        /**
         * Null when the block occupies no time at all — many words emitted
         * against a single instant. That is the most implausible density there
         * is rather than an absent measurement, but it has no finite value, and
         * `INF` cannot be JSON-encoded into a section's metadata.
         */
        public ?float $wordsPerMinute,
        public ?string $phrase = null,
        public ?int $repeats = null,
    ) {}

    /**
     * Rebuild a block from the shape {@see self::toArray()} records on a run.
     *
     * A block read back from a run is the same claim as the one the screen made, so a consumer
     * reading `service_transcript_suspect_blocks` need not re-screen a transcript file that may
     * no longer be reachable. Unknown keys are ignored and absent ones take their defaults: the
     * density blocks carry no phrase or repeat count.
     *
     * @param  array<string, mixed>  $block
     */
    public static function fromArray(array $block): self
    {
        return new self(
            start: (float) ($block['start'] ?? 0.0),
            end: (float) ($block['end'] ?? 0.0),
            reason: (string) ($block['reason'] ?? self::REASON_REPEATED_PHRASE),
            words: (int) ($block['words'] ?? 0),
            wordsPerMinute: isset($block['words_per_minute']) && is_numeric($block['words_per_minute'])
                ? (float) $block['words_per_minute']
                : null,
            phrase: isset($block['phrase']) && is_string($block['phrase']) ? $block['phrase'] : null,
            repeats: isset($block['repeats']) && is_numeric($block['repeats']) ? (int) $block['repeats'] : null,
        );
    }

    public function seconds(): float
    {
        return $this->end - $this->start;
    }

    public function overlaps(float $start, float $end): bool
    {
        return $this->start < $end && $this->end > $start;
    }

    /**
     * @return SuspectTranscriptBlockShape
     */
    public function toArray(): array
    {
        $block = [
            'start' => round($this->start, 2),
            'end' => round($this->end, 2),
            'reason' => $this->reason,
            'words' => $this->words,
            'words_per_minute' => $this->wordsPerMinute === null ? null : round($this->wordsPerMinute, 1),
        ];

        if ($this->phrase !== null) {
            $block['phrase'] = $this->phrase;
        }

        if ($this->repeats !== null) {
            $block['repeats'] = $this->repeats;
        }

        return $block;
    }

    /**
     * The seconds covered by these blocks, counting shared seconds once.
     *
     * {@see ServiceTranscriptRepetitionScreen} already returns disjoint blocks,
     * but a caller may have merged the screens of two transcripts, and a double
     * count would inflate every fraction derived from this.
     *
     * @param  list<self>  $blocks
     */
    public static function coveredSeconds(array $blocks): float
    {
        $ordered = $blocks;
        usort($ordered, static fn (self $left, self $right): int => $left->start <=> $right->start);

        $seconds = 0.0;
        $reached = null;

        foreach ($ordered as $block) {
            $start = $reached === null ? $block->start : max($block->start, $reached);

            if ($block->end > $start) {
                $seconds += $block->end - $start;
            }

            $reached = $reached === null ? $block->end : max($reached, $block->end);
        }

        return $seconds;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use Closure;

/**
 * Replace the looping stretches of a transcript with a fresh decode of the audio
 * underneath them, keeping everything the loop did not touch.
 *
 * Deliberately not {@see ServiceTranscriptRecovery}, though the two share a
 * shape. That one recovers a *pathology window*: a region where the decode
 * collapsed entirely, which it re-transcribes whole and adopts whole. This
 * recovers a {@see ServiceTranscriptRepetitionScreen} block — a short loop
 * sitting inside otherwise sound text, typically 24 seconds in a service of an
 * hour. The two rules differ in what they are allowed to overwrite, and merging
 * them would mean one of the two doing the wrong thing.
 *
 * **Bounded and overlapping.** The decode is asked for the block plus padding on
 * each side, because a decoder given 24 seconds of audio with no lead-in has no
 * context to work from and tends to produce exactly the kind of confident
 * invention this exists to remove. But only the part of the retry that falls
 * *inside the original block* is kept. The padding is context, not content: the
 * cues either side of the loop were never suspect, and replacing them would put
 * sound text at risk to fix a neighbour and make every repair harder to review.
 *
 * **A retry that loops again is not an answer.** The kept region is screened
 * once more, and anything still looping is dropped and banked unobservable
 * rather than written back. That is the same judgement
 * {@see ServiceTranscriptRecovery} learned from sermon 1148 — accept region by
 * region, never as one verdict — applied at the smaller scale.
 *
 * **A retry that could not be obtained changes nothing.** The looping cues stay
 * exactly where they are and the section keeps its hold. They are known bad, but
 * deleting them would trade text a later pass could still replace for a blind
 * window nothing can, and the hold already says not to trust them.
 */
class ServiceTranscriptRepetitionRecovery
{
    public function __construct(
        private readonly ServiceTranscriptRepetitionScreen $screen,
    ) {}

    /**
     * @param  Closure(float, float, SuspectTranscriptBlock): ?ChurchServiceTranscript  $decode
     *                                                                                  Given the padded window bounds and the block, returns a decode of
     *                                                                                  that window in window-relative time, or null when the attempt could
     *                                                                                  not be made at all.
     */
    public function recover(ChurchServiceTranscript $transcript, Closure $decode): TranscriptRepetitionRecoveryResult
    {
        $padding = (float) config('media-processing.service_structure.repetition_screen.recovery_padding_seconds', 30);

        $cues = $transcript->cues;
        $windows = $transcript->unobservableWindows;
        $blocks = $this->screen->screen($transcript);

        $recovered = 0;
        $unavailable = 0;
        $stillLooping = 0;
        $wordsBefore = 0;
        $wordsAfter = 0;

        foreach ($blocks as $block) {
            $wordsBefore += $block->words;

            $start = max(0.0, $block->start - $padding);
            $end = min($transcript->duration > 0.0 ? $transcript->duration : $block->end + $padding, $block->end + $padding);

            $retry = $decode($start, $end, $block);

            if (! $retry instanceof ChurchServiceTranscript) {
                $unavailable++;

                continue;
            }

            // We looked at the audio, so the looping cues are known bad either
            // way. Only the block's own span is cleared: the padding was context.
            $cues = $this->withoutOverlapping($cues, $block->start, $block->end);

            $kept = [];

            foreach ($retry->cues as $cue) {
                $absoluteStart = $cue['start'] + $start;
                $absoluteEnd = $cue['end'] + $start;
                $midpoint = ($absoluteStart + $absoluteEnd) / 2;

                // By midpoint rather than by overlap, so a cue straddling the
                // block's edge lands on exactly one side and the join neither
                // duplicates nor drops it.
                if ($midpoint >= $block->start && $midpoint < $block->end) {
                    $kept[] = ['start' => $absoluteStart, 'end' => $absoluteEnd, 'text' => $cue['text']];
                }
            }

            $residue = $this->screen->screen(
                ChurchServiceTranscript::fromCues($kept, $transcript->duration, $transcript->source),
            );

            foreach ($residue as $looping) {
                $kept = $this->withoutOverlapping($kept, $looping->start, $looping->end);
                $windows[] = [
                    'start' => $looping->start,
                    'end' => $looping->end,
                    'reason' => 'repetition_retry_looped',
                ];
            }

            if ($residue !== []) {
                $stillLooping++;
            }

            if ($kept === []) {
                $windows[] = ['start' => $block->start, 'end' => $block->end, 'reason' => 'repetition_retry_empty'];

                continue;
            }

            $recovered++;
            $wordsAfter += array_sum(array_map(
                static fn (array $cue): int => str_word_count($cue['text']),
                $kept,
            ));

            $cues = [...$cues, ...$kept];
        }

        return new TranscriptRepetitionRecoveryResult(
            transcript: ChurchServiceTranscript::fromCues($cues, $transcript->duration, $transcript->source, $windows),
            blocks: count($blocks),
            recovered: $recovered,
            unavailable: $unavailable,
            stillLooping: $stillLooping,
            loopedWordsRemoved: $wordsBefore,
            wordsRecovered: $wordsAfter,
        );
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return list<array{start: float, end: float, text: string}>
     */
    private function withoutOverlapping(array $cues, float $start, float $end): array
    {
        return array_values(array_filter(
            $cues,
            static fn (array $cue): bool => $cue['end'] <= $start || $cue['start'] >= $end,
        ));
    }
}

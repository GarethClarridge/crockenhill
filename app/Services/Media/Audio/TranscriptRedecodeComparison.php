<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use InvalidArgumentException;

/**
 * H10b's detector-independent descriptive scores, not defect labels or recall.
 *
 * Both inputs must already use the same source timeline. Cue text is included
 * whole wherever it overlaps a window (the transcript DTO's slicing contract);
 * a boundary-crossing cue can therefore create alignment noise. No word timing
 * is invented. Identical-options controls must measure that noise before these
 * scores are used to select disagreements for source adjudication.
 *
 * @phpstan-type ComparisonWindow array{
 *     start: float, end: float, stored_tokens: int, new_tokens: int,
 *     token_distance: float, stored_repetition: float, new_repetition: float,
 *     stored_screen_overlap: bool|null
 * }
 */
class TranscriptRedecodeComparison
{
    /**
     * Null blocks mean the stored screen is unknown; [] means screened clear.
     * Every source window is returned, including agreement and empty windows.
     *
     * @param  list<SuspectTranscriptBlock>|null  $storedBlocks
     * @return list<ComparisonWindow>
     */
    public function compare(ChurchServiceTranscript $stored, ChurchServiceTranscript $new, ?array $storedBlocks): array
    {
        if (! is_finite($stored->duration) || $stored->duration < 0.0 || $stored->duration !== $new->duration) {
            throw new InvalidArgumentException('Comparison requires matching finite source durations.');
        }

        $windows = [];

        for ($start = 0.0; $start < $stored->duration; $start += 30.0) {
            $end = min($start + 30.0, $stored->duration);
            $storedTokens = $this->tokens($stored->sliceText($start, $end));
            $newTokens = $this->tokens($new->sliceText($start, $end));
            $windows[] = [
                'start' => $start,
                'end' => $end,
                'stored_tokens' => count($storedTokens),
                'new_tokens' => count($newTokens),
                'token_distance' => $this->distance($storedTokens, $newTokens),
                'stored_repetition' => $this->repetition($storedTokens),
                'new_repetition' => $this->repetition($newTokens),
                'stored_screen_overlap' => $this->screenOverlap($storedBlocks, $start, $end),
            ];
        }

        return $windows;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);

        return $matches[0];
    }

    /**
     * Token Levenshtein distance divided by the longer sequence length.
     * Two empty sequences agree; one empty sequence disagrees completely.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function distance(array $left, array $right): float
    {
        $length = max(count($left), count($right));

        if ($length === 0 || $left === $right) {
            return 0.0;
        }

        $previous = range(0, count($right));

        foreach ($left as $i => $leftToken) {
            $current = [$i + 1];

            foreach ($right as $j => $rightToken) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($leftToken === $rightToken ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return $previous[count($right)] / $length;
    }

    /**
     * Share of adjacent token pairs already encountered in this window.
     * A descriptive bigram redundancy score, never a repetition verdict.
     *
     * @param  list<string>  $tokens
     */
    private function repetition(array $tokens): float
    {
        $pairs = count($tokens) - 1;

        if ($pairs <= 0) {
            return 0.0;
        }

        $seen = [];
        $repeated = 0;

        for ($index = 0; $index < $pairs; $index++) {
            $key = $tokens[$index].' '.$tokens[$index + 1];
            $repeated += isset($seen[$key]) ? 1 : 0;
            $seen[$key] = true;
        }

        return $repeated / $pairs;
    }

    /** @param list<SuspectTranscriptBlock>|null $blocks */
    private function screenOverlap(?array $blocks, float $start, float $end): ?bool
    {
        if ($blocks === null) {
            return null;
        }

        foreach ($blocks as $block) {
            if ($block->overlaps($start, $end)) {
                return true;
            }
        }

        return false;
    }
}

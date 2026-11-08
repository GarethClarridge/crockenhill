<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\AgreedEdgeWords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Aligned word ends (whisper.cpp DTW) mark where each word ends (RA3, 11-08): a word starts where the
 * one before it ended, or 0.8 s before its own end after a pause. Punctuation tokens are not words.
 */
class AgreedEdgeWordsTest extends TestCase
{
    #[Test]
    public function aligned_words_start_at_the_previous_end_or_after_a_pause(): void
    {
        $timed = (new AgreedEdgeWords)->aligned([
            ['start' => 990.0, 'end' => 992.5, 'word' => ' me', 'aligned_end' => 991.0],
            ['start' => 992.5, 'end' => 992.6, 'word' => '.', 'aligned_end' => 992.6],
            ['start' => 993.0, 'end' => 993.3, 'word' => ' In', 'aligned_end' => 993.4],
            ['start' => 993.3, 'end' => 993.8, 'word' => ' Isaiah', 'aligned_end' => 993.75],
        ], 989.5);

        $this->assertEquals([
            ['start' => 990.2, 'end' => 991.0, 'word' => ' me'],
            ['start' => 992.6, 'end' => 993.4, 'word' => ' In'],
            ['start' => 993.4, 'end' => 993.75, 'word' => ' Isaiah'],
        ], array_map(static fn (array $word): array => ['start' => round($word['start'], 3)] + $word, $timed));
    }

    #[Test]
    public function a_first_word_starts_no_earlier_than_its_window(): void
    {
        $timed = (new AgreedEdgeWords)->aligned([['start' => 0.0, 'end' => 0.5, 'word' => ' Amen', 'aligned_end' => 1000.3]], 1000.0);

        $this->assertEqualsWithDelta(1000.0, $timed[0]['start'], 0.0001);
    }
}

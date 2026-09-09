<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Services\Media\Audio\ServiceTranscriptRepetitionRecovery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceTranscriptRepetitionRecoveryTest extends TestCase
{
    #[Test]
    public function it_replaces_the_loop_and_leaves_the_surrounding_text_untouched(): void
    {
        $transcript = $this->transcriptWithLoop();

        $result = app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            fn (float $start, float $end, SuspectTranscriptBlock $block): ChurchServiceTranscript => $this->decode($start, $block),
        );

        self::assertSame(1, $result->blocks);
        self::assertSame(1, $result->recovered);
        self::assertSame(0, $result->unavailable);

        $text = $result->transcript->sliceText(0.0, 1000.0);
        self::assertStringContainsString('Joshua and the campaigns in the south', $text);
        self::assertStringNotContainsString('God did what was necessary', $text);

        // The cues either side of the block were never suspect.
        self::assertStringContainsString('And so we come to the sermon.', $text);
        self::assertStringContainsString('The rest of the sermon.', $text);
    }

    #[Test]
    public function it_asks_for_padding_but_writes_back_only_the_block(): void
    {
        $transcript = $this->transcriptWithLoop();
        $asked = [];

        app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            function (float $start, float $end, SuspectTranscriptBlock $block) use (&$asked): ChurchServiceTranscript {
                $asked[] = [$start, $end, $block->start, $block->end];

                return $this->decode($start, $block);
            },
        );

        [$windowStart, $windowEnd, $blockStart, $blockEnd] = $asked[0];

        self::assertSame($blockStart - 30.0, $windowStart);
        self::assertSame($blockEnd + 30.0, $windowEnd);
    }

    #[Test]
    public function it_does_not_duplicate_the_padding_text_that_already_exists(): void
    {
        // The decode covers the padding too, and those seconds already hold good
        // cues. Splicing the whole retry would say everything in them twice.
        $transcript = $this->transcriptWithLoop();

        $result = app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            function (float $start, float $end, SuspectTranscriptBlock $block): ChurchServiceTranscript {
                $cues = [
                    ['start' => 0.0, 'end' => 30.0, 'text' => 'And so we come to the sermon.'],
                    ...array_map(
                        static fn (array $cue): array => $cue,
                        $this->decode($start, $block)->cues,
                    ),
                    ['start' => $end - $start - 5.0, 'end' => $end - $start, 'text' => 'The rest of the sermon.'],
                ];

                return ChurchServiceTranscript::fromCues($cues, $end - $start, ChurchServiceTranscript::SOURCE_MOCK);
            },
        );

        $text = $result->transcript->sliceText(0.0, 1000.0);

        self::assertSame(1, substr_count($text, 'And so we come to the sermon.'));
        self::assertSame(1, substr_count($text, 'The rest of the sermon.'));
    }

    #[Test]
    public function it_leaves_the_looping_cues_alone_when_the_audio_cannot_be_reached(): void
    {
        // Known bad, but deleting them trades text a later pass could replace
        // for a blind window nothing can. The hold already says not to trust it.
        $transcript = $this->transcriptWithLoop();

        $result = app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            static fn (): ?ChurchServiceTranscript => null,
        );

        self::assertSame(1, $result->unavailable);
        self::assertSame(0, $result->recovered);
        self::assertFalse($result->changedAnything());
        self::assertStringContainsString(
            'God did what was necessary',
            $result->transcript->sliceText(0.0, 1000.0),
        );
        self::assertSame([], $result->transcript->unobservableWindows);
    }

    #[Test]
    public function it_banks_a_retry_that_loops_again_rather_than_writing_it_back(): void
    {
        $transcript = $this->transcriptWithLoop();

        $result = app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            function (float $start, float $end, SuspectTranscriptBlock $block): ChurchServiceTranscript {
                $cues = [];

                for ($index = 0; $index < 12; $index++) {
                    $cues[] = [
                        'start' => ($block->start - $start) + $index * 4.0,
                        'end' => ($block->start - $start) + 4.0 + $index * 4.0,
                        'text' => 'Amen and amen and amen for ever and ever.',
                    ];
                }

                return ChurchServiceTranscript::fromCues($cues, $end - $start, ChurchServiceTranscript::SOURCE_MOCK);
            },
        );

        self::assertSame(1, $result->stillLooping);
        self::assertSame(0, $result->recovered);
        self::assertStringNotContainsString('Amen and amen', $result->transcript->sliceText(0.0, 1000.0));
        self::assertNotSame([], $result->transcript->unobservableWindows);
    }

    #[Test]
    public function it_reports_a_silent_window_as_empty_rather_than_recovered(): void
    {
        $transcript = $this->transcriptWithLoop();

        $result = app(ServiceTranscriptRepetitionRecovery::class)->recover(
            $transcript,
            static fn (float $start, float $end): ChurchServiceTranscript => ChurchServiceTranscript::fromCues(
                [], $end - $start, ChurchServiceTranscript::SOURCE_MOCK,
            ),
        );

        self::assertSame(0, $result->recovered);
        self::assertSame('repetition_retry_empty', $result->transcript->unobservableWindows[0]['reason']);
    }

    private function decode(float $windowStart, SuspectTranscriptBlock $block): ChurchServiceTranscript
    {
        // Window-relative, as a real decode of an extracted clip would be.
        $offset = $block->start - $windowStart;

        return ChurchServiceTranscript::fromCues([
            ['start' => $offset, 'end' => $offset + 24.0, 'text' => 'Joshua and the campaigns in the south of the land.'],
            ['start' => $offset + 24.0, 'end' => $offset + 48.0, 'text' => 'And the kings that came against him there.'],
        ], 108.0, ChurchServiceTranscript::SOURCE_MOCK);
    }

    private function transcriptWithLoop(): ChurchServiceTranscript
    {
        $cues = [['start' => 60.0, 'end' => 100.0, 'text' => 'And so we come to the sermon.']];

        for ($index = 0; $index < 12; $index++) {
            $cues[] = [
                'start' => 100.0 + $index * 4.0,
                'end' => 104.0 + $index * 4.0,
                'text' => 'God did what was necessary in order to make sure they won.',
            ];
        }

        $cues[] = ['start' => 200.0, 'end' => 400.0, 'text' => 'The rest of the sermon.'];

        return ChurchServiceTranscript::fromCues($cues, 1000.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}

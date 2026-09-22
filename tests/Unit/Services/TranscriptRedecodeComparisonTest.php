<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Services\Media\Audio\TranscriptRedecodeComparison;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TranscriptRedecodeComparisonTest extends TestCase
{
    #[Test]
    public function it_scores_substitutions_without_repetition_or_screen_flags(): void
    {
        $result = (new TranscriptRedecodeComparison)->compare(
            $this->transcript('We believe this promise'),
            $this->transcript('We reject this promise'),
            [],
        );

        $this->assertSame(0.25, $result[0]['token_distance']);
        $this->assertSame(0.0, $result[0]['stored_repetition']);
        $this->assertSame(0.0, $result[0]['new_repetition']);
        $this->assertFalse($result[0]['stored_screen_overlap']);
    }

    #[Test]
    public function repetition_and_screen_coverage_cannot_change_disagreement(): void
    {
        $service = new TranscriptRedecodeComparison;
        $stored = $this->transcript('Praise God praise God praise God');
        $new = $this->transcript('Praise God today');
        $block = new SuspectTranscriptBlock(0.0, 20.0, 'repeated_phrase_loop', 6, 18.0);
        $clear = $service->compare($stored, $new, []);
        $covered = $service->compare($stored, $new, [$block]);
        $reverse = $service->compare($new, $stored, null);

        $this->assertSame($clear[0]['token_distance'], $covered[0]['token_distance']);
        $this->assertSame($clear[0]['token_distance'], $reverse[0]['token_distance']);
        $this->assertSame(0.6, $clear[0]['stored_repetition']);
        $this->assertSame(0.0, $clear[0]['new_repetition']);
        $this->assertSame(0.6, $reverse[0]['new_repetition']);
        $this->assertTrue($covered[0]['stored_screen_overlap']);
        $this->assertNull($reverse[0]['stored_screen_overlap']);
    }

    #[Test]
    public function windows_follow_source_time_including_empty_and_partial_windows(): void
    {
        $stored = ChurchServiceTranscript::fromCues([
            ['start' => 30.0, 'end' => 40.0, 'text' => 'Hello WORLD!'],
        ], 65.0, 'mock');
        $new = ChurchServiceTranscript::fromCues([
            ['start' => 30.0, 'end' => 40.0, 'text' => 'hello world'],
            ['start' => 60.0, 'end' => 65.0, 'text' => 'Amen'],
        ], 65.0, 'mock');
        $block = new SuspectTranscriptBlock(30.0, 60.0, 'repeated_phrase_loop', 2, 4.0);
        $windows = (new TranscriptRedecodeComparison)->compare($stored, $new, [$block]);

        $this->assertSame([0.0, 30.0, 60.0], array_column($windows, 'start'));
        $this->assertSame([30.0, 60.0, 65.0], array_column($windows, 'end'));
        $this->assertSame([0.0, 0.0, 1.0], array_column($windows, 'token_distance'));
        $this->assertSame([false, true, false], array_column($windows, 'stored_screen_overlap'));
        $this->assertSame(0, $windows[2]['stored_tokens']);
        $this->assertSame(1, $windows[2]['new_tokens']);
    }

    #[Test]
    public function crossing_cues_are_included_whole_in_each_overlapped_window(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 29.0, 'end' => 31.0, 'text' => 'crossing cue'],
        ], 60.0, 'mock');
        $windows = (new TranscriptRedecodeComparison)->compare($transcript, $transcript, []);

        $this->assertSame([2, 2], array_column($windows, 'stored_tokens'));
        $this->assertSame([0.0, 0.0], array_column($windows, 'token_distance'));
    }

    #[Test]
    public function different_timeline_durations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TranscriptRedecodeComparison)->compare(
            $this->transcript('hello'),
            ChurchServiceTranscript::fromCues([], 60.0, 'mock'),
            [],
        );
    }

    private function transcript(string $text): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => $text],
        ], 30.0, 'mock');
    }
}

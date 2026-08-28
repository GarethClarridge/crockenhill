<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ChurchServiceTranscript;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceTranscriptDataTest extends TestCase
{
    #[Test]
    public function it_round_trips_and_prompts_with_unobservable_windows(): void
    {
        $transcript = ChurchServiceTranscript::fromCues(
            [['start' => 1200.0, 'end' => 1230.0, 'text' => 'The reading begins.']],
            1500.0,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
            [['start' => 0.0, 'end' => 1200.0, 'reason' => 'retranscription_failed']],
        );

        $restored = ChurchServiceTranscript::fromArray($transcript->toArray());

        $this->assertSame($transcript->unobservableWindows, $restored->unobservableWindows);
        $this->assertStringContainsString('[0:00-20:00] TRANSCRIPT UNOBSERVABLE', $restored->toPromptText());
    }

    #[Test]
    public function from_cues_normalises_orders_and_drops_invalid_cues(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 60.0, 'end' => 90.0, 'text' => 'Second cue.'],
            ['start' => 0.0, 'end' => 30.0, 'text' => '  First cue.  '],
            ['start' => 100.0, 'end' => 95.0, 'text' => 'End before start gets clamped.'],
            ['start' => 5.0, 'end' => 10.0, 'text' => '   '],
            ['start' => 'not-a-number', 'end' => 10.0, 'text' => 'Dropped.'],
            'not-an-array',
            ['start' => -5.0, 'end' => 3.0, 'text' => 'Negative start clamps to zero.'],
        ], 120.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame(
            ['First cue.', 'Negative start clamps to zero.', 'Second cue.', 'End before start gets clamped.'],
            array_column($transcript->cues, 'text')
        );
        $this->assertSame(0.0, $transcript->cues[0]['start']);
        $this->assertSame(100.0, $transcript->cues[3]['end'], 'End time is clamped up to the start time.');
        $this->assertSame(120.0, $transcript->duration);
    }

    #[Test]
    public function duration_is_extended_to_cover_the_final_cue(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 200.0, 'text' => 'Cue running past the reported duration.'],
        ], 150.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame(200.0, $transcript->duration);
    }

    #[Test]
    public function round_trips_through_array_serialisation(): void
    {
        $original = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 12.5, 'text' => 'Good morning and welcome.'],
            ['start' => 12.5, 'end' => 30.0, 'text' => 'Our first hymn this morning.'],
        ], 3600.0, ChurchServiceTranscript::SOURCE_WHISPER_API);

        $decoded = json_decode((string) json_encode($original), true);
        $restored = ChurchServiceTranscript::fromArray($decoded);

        $this->assertSame($original->toArray(), $restored->toArray());
        $this->assertSame(ChurchServiceTranscript::SOURCE_WHISPER_API, $restored->source);
    }

    #[Test]
    public function from_array_tolerates_malformed_payloads(): void
    {
        $transcript = ChurchServiceTranscript::fromArray(['unexpected' => 'shape']);

        $this->assertTrue($transcript->isEmpty());
        $this->assertSame(0.0, $transcript->duration);
        $this->assertSame(ChurchServiceTranscript::SOURCE_MOCK, $transcript->source);
    }

    #[Test]
    public function prompt_text_renders_compact_minute_second_ranges(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 5.0, 'text' => 'Welcome everyone.'],
            ['start' => 65.4, 'end' => 70.0, 'text' => 'Our first hymn.'],
            ['start' => 5525.0, 'end' => 5530.0, 'text' => 'A closing word.'],
        ], 5600.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame(
            "[0:00-0:05] Welcome everyone.\n[1:05-1:10] Our first hymn.\n[92:05-92:10] A closing word.",
            $transcript->toPromptText()
        );
    }

    #[Test]
    public function slice_text_returns_cues_overlapping_the_window(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Before the window.'],
            ['start' => 10.0, 'end' => 20.0, 'text' => 'Overlaps the start.'],
            ['start' => 20.0, 'end' => 30.0, 'text' => 'Fully inside.'],
            ['start' => 30.0, 'end' => 40.0, 'text' => 'Overlaps the end.'],
            ['start' => 40.0, 'end' => 50.0, 'text' => 'After the window.'],
        ], 50.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame(
            'Overlaps the start. Fully inside. Overlaps the end.',
            $transcript->sliceText(15.0, 35.0)
        );
        $this->assertSame('', $transcript->sliceText(60.0, 70.0));
    }

    #[Test]
    public function slice_text_excludes_cues_touching_only_the_boundaries(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Ends exactly at the window start.'],
            ['start' => 10.0, 'end' => 20.0, 'text' => 'Inside.'],
            ['start' => 20.0, 'end' => 30.0, 'text' => 'Starts exactly at the window end.'],
        ], 30.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame('Inside.', $transcript->sliceText(10.0, 20.0));
    }

    #[Test]
    public function speech_duration_sums_cue_coverage(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Ten seconds.'],
            ['start' => 30.0, 'end' => 45.5, 'text' => 'Fifteen and a half.'],
        ], 60.0, ChurchServiceTranscript::SOURCE_MOCK);

        $this->assertSame(25.5, $transcript->speechDuration());
    }
}

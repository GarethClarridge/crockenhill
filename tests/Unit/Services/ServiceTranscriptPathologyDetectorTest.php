<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceTranscriptPathologyDetectorTest extends TestCase
{
    #[Test]
    public function it_detects_the_repeated_thank_you_hallucination_as_one_window(): void
    {
        $cues = [];

        for ($index = 0; $index < 40; $index++) {
            $cues[] = [
                'start' => $index * 30.0,
                'end' => ($index + 1) * 30.0,
                'text' => 'Thank you.',
            ];
        }

        $windows = app(ServiceTranscriptPathologyDetector::class)->detect(
            ChurchServiceTranscript::fromCues($cues, 2736.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $windows);
        $this->assertSame(0.0, $windows[0]['start']);
        $this->assertSame(1200.0, $windows[0]['end']);
        $this->assertSame(40, $windows[0]['cue_count']);
        $this->assertSame('repeated_low_information_cues', $windows[0]['reason']);
    }

    #[Test]
    public function it_does_not_flag_a_short_legitimate_repeated_response(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 5.0, 'text' => 'Amen.'],
            ['start' => 5.0, 'end' => 10.0, 'text' => 'Amen.'],
            ['start' => 10.0, 'end' => 15.0, 'text' => 'Amen.'],
            ['start' => 15.0, 'end' => 20.0, 'text' => 'Let us pray together.'],
        ], 20.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        $this->assertSame([], app(ServiceTranscriptPathologyDetector::class)->detect($transcript));
    }

    #[Test]
    public function it_detects_the_repeated_unrelated_class_line_from_the_historic_calibration(): void
    {
        $cues = [];

        for ($start = 570.0; $start < 900.0; $start += 2.0) {
            $cues[] = [
                'start' => $start,
                'end' => $start + 2.0,
                'text' => "Yeah, I'm gonna do the rest of the class.",
            ];
        }

        $windows = app(ServiceTranscriptPathologyDetector::class)->detect(
            ChurchServiceTranscript::fromCues($cues, 900.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $windows);
        $this->assertSame(570.0, $windows[0]['start']);
        $this->assertSame(900.0, $windows[0]['end']);
        $this->assertSame('repeated_low_information_cues', $windows[0]['reason']);
    }
}

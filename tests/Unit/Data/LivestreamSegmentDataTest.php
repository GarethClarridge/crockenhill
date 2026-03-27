<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSegmentDataTest extends TestCase
{
    private function makeSegment(
        float $startTime = 0.0,
        float $endTime = 600.0,
        float $duration = 600.0,
        string $classification = 'speech',
        float $avgRms = 0.05,
        float $peakRms = 0.2,
        bool $isSermonCandidate = false,
        int $segmentOrder = 0,
    ): LivestreamSegment {
        return new LivestreamSegment(
            startTime: $startTime,
            endTime: $endTime,
            duration: $duration,
            classification: $classification,
            avgRms: $avgRms,
            peakRms: $peakRms,
            isSermonCandidate: $isSermonCandidate,
            segmentOrder: $segmentOrder,
        );
    }

    // ── Classification helpers ────────────────────────────────────────────────

    #[Test]
    public function it_identifies_speech_classification(): void
    {
        $segment = $this->makeSegment(classification: LivestreamSegmentClassification::Speech->value);

        $this->assertTrue($segment->isSpeech());
        $this->assertFalse($segment->isSong());
        $this->assertFalse($segment->isSilence());
    }

    #[Test]
    public function it_identifies_song_classification(): void
    {
        $segment = $this->makeSegment(classification: LivestreamSegmentClassification::Song->value);

        $this->assertFalse($segment->isSpeech());
        $this->assertTrue($segment->isSong());
        $this->assertFalse($segment->isSilence());
    }

    #[Test]
    public function it_identifies_silence_classification(): void
    {
        $segment = $this->makeSegment(classification: LivestreamSegmentClassification::Silence->value);

        $this->assertFalse($segment->isSpeech());
        $this->assertFalse($segment->isSong());
        $this->assertTrue($segment->isSilence());
    }

    // ── Duration ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_converts_duration_to_minutes(): void
    {
        $segment = $this->makeSegment(duration: 90.0);

        $this->assertSame(1.5, $segment->getDurationInMinutes());
    }

    // ── Time formatting ───────────────────────────────────────────────────────

    #[Test]
    public function it_formats_start_time_without_hours(): void
    {
        $segment = $this->makeSegment(startTime: 125.0);

        $this->assertSame('02:05', $segment->getStartTimeFormatted());
    }

    #[Test]
    public function it_formats_start_time_with_hours(): void
    {
        $segment = $this->makeSegment(startTime: 3661.0);

        $this->assertSame('01:01:01', $segment->getStartTimeFormatted());
    }

    #[Test]
    public function it_formats_end_time(): void
    {
        $segment = $this->makeSegment(endTime: 300.0);

        $this->assertSame('05:00', $segment->getEndTimeFormatted());
    }

    // ── Duration formatting ───────────────────────────────────────────────────

    #[Test]
    public function it_formats_duration_under_one_hour(): void
    {
        $segment = $this->makeSegment(duration: 125.0);

        $this->assertSame('2m 5s', $segment->getDurationFormatted());
    }

    #[Test]
    public function it_formats_duration_over_one_hour(): void
    {
        $segment = $this->makeSegment(duration: 3725.0);

        $this->assertStringContainsString('h', $segment->getDurationFormatted());
        $this->assertStringContainsString('m', $segment->getDurationFormatted());
    }

    // ── Sermon candidate flag ─────────────────────────────────────────────────

    #[Test]
    public function it_defaults_to_not_sermon_candidate(): void
    {
        $segment = $this->makeSegment();

        $this->assertFalse($segment->isSermonCandidate);
    }

    #[Test]
    public function it_can_be_marked_as_sermon_candidate(): void
    {
        $segment = $this->makeSegment(isSermonCandidate: true);

        $this->assertTrue($segment->isSermonCandidate);
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    #[Test]
    public function it_accepts_optional_metadata(): void
    {
        $segment = new LivestreamSegment(
            startTime: 0.0,
            endTime: 10.0,
            duration: 10.0,
            classification: 'speech',
            avgRms: 0.1,
            peakRms: 0.3,
            metadata: ['confidence' => 0.95],
        );

        $this->assertSame(['confidence' => 0.95], $segment->metadata);
    }

    #[Test]
    public function it_defaults_metadata_to_null(): void
    {
        $segment = $this->makeSegment();

        $this->assertNull($segment->metadata);
    }
}

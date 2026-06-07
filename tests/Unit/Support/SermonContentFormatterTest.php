<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SermonContentFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonContentFormatterTest extends TestCase
{
    #[Test]
    public function human_duration_returns_null_for_missing_or_non_positive_durations(): void
    {
        $this->assertNull(SermonContentFormatter::humanDuration(null));
        $this->assertNull(SermonContentFormatter::humanDuration(0));
        $this->assertNull(SermonContentFormatter::humanDuration(-60));
    }

    #[Test]
    public function human_duration_formats_minutes_only(): void
    {
        $this->assertSame('30m', SermonContentFormatter::humanDuration(1800));
    }

    #[Test]
    public function human_duration_formats_hours_and_minutes(): void
    {
        $this->assertSame('1h 30m', SermonContentFormatter::humanDuration(5400));
    }

    #[Test]
    public function human_duration_handles_exactly_one_hour(): void
    {
        $this->assertSame('1h 0m', SermonContentFormatter::humanDuration(3600));
    }

    #[Test]
    public function human_duration_floors_sub_minute_remainders(): void
    {
        $this->assertSame('0m', SermonContentFormatter::humanDuration(45));
    }

    #[Test]
    public function iso8601_duration_returns_null_for_missing_or_non_positive_durations(): void
    {
        $this->assertNull(SermonContentFormatter::iso8601Duration(null));
        $this->assertNull(SermonContentFormatter::iso8601Duration(0));
        $this->assertNull(SermonContentFormatter::iso8601Duration(-1));
    }

    #[Test]
    public function iso8601_duration_formats_correctly(): void
    {
        $this->assertSame('PT45M', SermonContentFormatter::iso8601Duration(2700));
        $this->assertSame('PT1H', SermonContentFormatter::iso8601Duration(3600));
    }

    #[Test]
    public function plain_text_outline_returns_null_for_empty_or_non_array_points(): void
    {
        $this->assertNull(SermonContentFormatter::plainTextOutline(null));
        $this->assertNull(SermonContentFormatter::plainTextOutline([]));
        $this->assertNull(SermonContentFormatter::plainTextOutline('not an array'));
    }

    #[Test]
    public function plain_text_outline_formats_structured_and_scalar_points(): void
    {
        $points = [
            ['point' => 'First point', 'sub_points' => ['First sub point']],
            'Second point',
        ];

        $this->assertSame(
            "1. First point\n   - First sub point\n2. Second point",
            SermonContentFormatter::plainTextOutline($points),
        );
    }

    #[Test]
    public function plain_text_outline_labels_untitled_points_carrying_sub_points(): void
    {
        $points = [
            ['point' => '', 'sub_points' => ['Orphan sub point']],
        ];

        $this->assertSame(
            "1. (Untitled point)\n   - Orphan sub point",
            SermonContentFormatter::plainTextOutline($points),
        );
    }

    #[Test]
    public function plain_text_outline_skips_fully_empty_points(): void
    {
        $points = [
            ['point' => '', 'sub_points' => []],
            'Real point',
        ];

        $this->assertSame('1. Real point', SermonContentFormatter::plainTextOutline($points));
    }
}

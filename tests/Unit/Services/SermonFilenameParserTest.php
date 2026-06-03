<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Services\SermonFilenameParser;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonFilenameParserTest extends TestCase
{
    private SermonFilenameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SermonFilenameParser;
    }

    #[Test]
    public function it_extracts_iso_european_and_compact_date_formats(): void
    {
        $cases = [
            '2024-01-15_sermon.mp3' => '2024-01-15',  // ISO
            '15-01-2024_sermon.mp3' => '2024-01-15',  // European
            '20240115_sermon.mp3' => '2024-01-15',    // Compact
            '2024/06/30_evening.mp3' => '2024-06-30', // ISO with slashes
        ];

        foreach ($cases as $filename => $expected) {
            $this->assertSame(
                $expected,
                $this->parser->extractDateFromFilename($filename)->format('Y-m-d'),
                "Failed for filename: {$filename}",
            );
        }
    }

    #[Test]
    public function it_extracts_named_month_dates_in_both_orderings(): void
    {
        $cases = [
            'Easter Sunday 5th April 2026.mp4' => '2026-04-05', // day-month-year with ordinal
            'April 5 2026 evening service.mp4' => '2026-04-05', // month-day-year
            'Dec 25th 2024 Christmas.mp4' => '2024-12-25',      // abbreviated month
        ];

        foreach ($cases as $filename => $expected) {
            $this->assertSame(
                $expected,
                $this->parser->extractDateFromFilename($filename)->format('Y-m-d'),
                "Failed for filename: {$filename}",
            );
        }
    }

    #[Test]
    public function it_falls_back_to_today_when_a_matched_date_pattern_is_invalid(): void
    {
        // 2024-13-45 matches the ISO pattern but is not a valid calendar date.
        // The &$matched short-circuit must prevent year-only extraction here.
        $this->assertSame(
            Carbon::today()->format('Y-m-d'),
            $this->parser->extractDateFromFilename('2024-13-45_sermon.mp3')->format('Y-m-d'),
        );
    }

    #[Test]
    public function try_extract_returns_null_when_no_date_pattern_is_present(): void
    {
        $this->assertNull($this->parser->tryExtractDateFromFilename('sermon.mp3'));
        $this->assertNull($this->parser->tryExtractDateFromFilename('morning_service.mp3'));
    }

    #[Test]
    public function try_extract_returns_null_for_a_matched_but_invalid_date(): void
    {
        // Distinguishes "no date" from "invalid date": both yield null, but via
        // different branches — this pins the &$matched path returning the null explicitDate.
        $this->assertNull($this->parser->tryExtractDateFromFilename('2024-13-45_sermon.mp3'));
    }

    #[Test]
    public function it_preserves_dates_containing_dots(): void
    {
        $this->assertSame(
            '2024-01-15',
            $this->parser->extractDateFromFilename('2024-01-15.backup.mp3')->format('Y-m-d'),
        );
    }

    #[Test]
    public function it_determines_service_from_time_using_the_2pm_cutoff(): void
    {
        $this->assertSame(SermonService::Morning, $this->parser->determineServiceFromTime(Carbon::parse('10:30')));
        $this->assertSame(SermonService::Morning, $this->parser->determineServiceFromTime(Carbon::parse('13:59')));
        $this->assertSame(SermonService::Evening, $this->parser->determineServiceFromTime(Carbon::parse('14:00')));
        $this->assertSame(SermonService::Evening, $this->parser->determineServiceFromTime(Carbon::parse('18:30')));
        // Before 06:00 counts as Evening (late-night recording, not a morning service).
        $this->assertSame(SermonService::Evening, $this->parser->determineServiceFromTime(Carbon::parse('05:00')));
    }

    #[Test]
    public function it_determines_service_from_filename_patterns(): void
    {
        $this->assertSame(SermonService::Morning, $this->parser->determineServiceFromFilename('morning_service.mp3'));
        $this->assertSame(SermonService::Evening, $this->parser->determineServiceFromFilename('evening_worship.mp3'));
        // Embedded HH-MM time wins over keywords.
        $this->assertSame(SermonService::Evening, $this->parser->determineServiceFromFilename('service_18-30.mkv'));
        $this->assertSame(SermonService::Morning, $this->parser->determineServiceFromFilename('service_10-30.mp3'));
        // No recognisable pattern defaults to Morning.
        $this->assertSame(SermonService::Morning, $this->parser->determineServiceFromFilename('untitled.mp3'));
    }

    #[Test]
    public function it_validates_calendar_dates_and_bounds(): void
    {
        $this->assertTrue($this->parser->isValidDate(2024, 2, 29));  // Leap year
        $this->assertTrue($this->parser->isValidDate(2000, 1, 1));

        $this->assertFalse($this->parser->isValidDate(2023, 2, 29)); // Not a leap year
        $this->assertFalse($this->parser->isValidDate(2024, 13, 1)); // Invalid month
        $this->assertFalse($this->parser->isValidDate(2024, 1, 32)); // Invalid day
        $this->assertFalse($this->parser->isValidDate(1800, 1, 1));  // Too old
        $this->assertFalse($this->parser->isValidDate(2099, 1, 1));  // Too far in the future
    }
}

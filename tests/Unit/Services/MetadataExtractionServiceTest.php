<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SermonMetadata;
use App\Enums\SermonService;
use App\Services\Processing\MetadataExtractionService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetadataExtractionServiceTest extends TestCase
{
    private MetadataExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to ensure deterministic date-fallback assertions.
        // We use 2026 to ensure the 2026 dates in test fixtures are valid.
        Carbon::setTestNow('2026-05-27 10:00:00');
        $this->service = new MetadataExtractionService;
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_extracts_iso_date_format_from_filename(): void
    {
        $testCases = [
            '2024-01-15_sermon.mp3' => '2024-01-15',
            '2024-12-25-christmas.mp3' => '2024-12-25',
            '2024.03.10 morning service.mp3' => '2024-03-10',
            '2024/06/30_evening.mp3' => '2024-06-30',
            'sermon_2024-07-04.mp3' => '2024-07-04',
        ];

        foreach ($testCases as $filename => $expectedDate) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expectedDate, $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_european_date_format_from_filename(): void
    {
        $testCases = [
            '15-01-2024_sermon.mp3' => '2024-01-15',
            '25-12-2024-christmas.mp3' => '2024-12-25',
            '10.03.2024 morning service.mp3' => '2024-03-10',
            '30/06/2024_evening.mp3' => '2024-06-30',
            'sermon_04-07-2024.mp3' => '2024-07-04',
        ];

        foreach ($testCases as $filename => $expectedDate) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expectedDate, $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_compact_date_format_from_filename(): void
    {
        $testCases = [
            '20240115_sermon.mp3' => '2024-01-15',
            '20241225christmas.mp3' => '2024-12-25',
            'sermon20240310.mp3' => '2024-03-10',
            '20240630evening.mp3' => '2024-06-30',
        ];

        foreach ($testCases as $filename => $expectedDate) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expectedDate, $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_named_month_date_format_from_filename(): void
    {
        $testCases = [
            'Easter Sunday 5th April 2026.mp4' => '2026-04-05',
            '5 April 2026 morning service.mp4' => '2026-04-05',
            'April 5 2026 evening service.mp4' => '2026-04-05',
            'Dec 25th 2024 Christmas.mp4' => '2024-12-25',
        ];

        foreach ($testCases as $filename => $expectedDate) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expectedDate, $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_year_only_when_no_full_date_found(): void
    {
        $testCases = [
            'sermon_2024.mp3',
            '2024_morning_service.mp3',
            'christmas_2024.mp3',
        ];

        foreach ($testCases as $filename) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals(2024, $result->year, "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_falls_back_to_current_date_when_no_date_found(): void
    {
        $testCases = [
            'sermon.mp3',
            'morning_service.mp3',
            'no_date_here.mp3',
        ];

        foreach ($testCases as $filename) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals('2026-05-27', $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_prefers_filename_date_over_client_modified_date_for_video_uploads(): void
    {
        $result = $this->service->extractDateFromVideo(
            '/missing/Easter Sunday 5th April 2026.mp4',
            '2026-04-06'
        );

        $this->assertSame('2026-04-05', $result->toDateString());
    }

    #[Test]
    public function it_uses_client_modified_date_for_video_uploads_when_filename_has_no_date(): void
    {
        $result = $this->service->extractDateFromVideo(
            '/missing/easter-sunday-service.mp4',
            '2026-04-06'
        );

        $this->assertSame('2026-04-06', $result->toDateString());
    }

    #[Test]
    public function it_uses_client_modified_date_when_video_metadata_cannot_be_read_and_filename_has_no_date(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'video-date-');
        $this->assertIsString($tempPath);

        $videoPath = $tempPath.'.mp4';
        $this->assertTrue(rename($tempPath, $videoPath));
        file_put_contents($videoPath, 'not a real video file');

        try {
            $result = $this->service->extractDateFromVideo($videoPath, '2026-04-06');
        } finally {
            @unlink($videoPath);
            @unlink($tempPath);
        }

        $this->assertSame('2026-04-06', $result->toDateString());
    }

    #[Test]
    public function it_validates_dates_correctly(): void
    {
        // Valid dates
        $this->assertTrue($this->service->isValidDate(2024, 2, 29)); // Leap year
        $this->assertTrue($this->service->isValidDate(2024, 12, 31));
        $this->assertTrue($this->service->isValidDate(2000, 1, 1));

        // Invalid dates
        $this->assertFalse($this->service->isValidDate(2023, 2, 29)); // Not leap year
        $this->assertFalse($this->service->isValidDate(2024, 13, 1)); // Invalid month
        $this->assertFalse($this->service->isValidDate(2024, 1, 32)); // Invalid day
        $this->assertFalse($this->service->isValidDate(1800, 1, 1)); // Too old
        $this->assertFalse($this->service->isValidDate(2030, 1, 1)); // Too far in future
    }

    #[Test]
    public function it_handles_invalid_dates_gracefully(): void
    {
        $testCases = [
            '2024-13-01_sermon.mp3', // Invalid month
            '2024-02-30_sermon.mp3', // Invalid day for February
            '1800-01-01_sermon.mp3', // Too old
        ];

        foreach ($testCases as $filename) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals('2026-05-27', $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_determines_service_from_time_correctly(): void
    {
        // Morning times (6 AM - 2 PM)
        $morningTimes = [
            Carbon::createFromTime(6, 0),
            Carbon::createFromTime(10, 30),
            Carbon::createFromTime(11, 0),
            Carbon::createFromTime(13, 59),
        ];

        foreach ($morningTimes as $time) {
            $result = $this->service->determineServiceFromTime($time);
            $this->assertEquals(SermonService::Morning, $result, "Failed for time: {$time->format('H:i')}");
        }

        // Evening times (2 PM onwards and before 6 AM)
        $eveningTimes = [
            Carbon::createFromTime(14, 0),
            Carbon::createFromTime(18, 30),
            Carbon::createFromTime(19, 0),
            Carbon::createFromTime(23, 59),
            Carbon::createFromTime(0, 0),
            Carbon::createFromTime(5, 59),
        ];

        foreach ($eveningTimes as $time) {
            $result = $this->service->determineServiceFromTime($time);
            $this->assertEquals(SermonService::Evening, $result, "Failed for time: {$time->format('H:i')}");
        }
    }

    #[Test]
    public function it_determines_service_from_filename_patterns(): void
    {
        // Morning patterns
        $morningFilenames = [
            'morning_sermon.mp3',
            'sermon_am.mp3',
            'am_service.mp3',
            '10:30_service.mp3',
            '11-00_sermon.mp3',
            '1030_morning.mp3',
            '1100service.mp3',
        ];

        foreach ($morningFilenames as $filename) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Morning, $result, "Failed for filename: {$filename}");
        }

        // Evening patterns
        $eveningFilenames = [
            'evening_sermon.mp3',
            'sermon_pm.mp3',
            'pm_service.mp3',
            '630_evening.mp3', // "630" without separator won't match time pattern, falls through to "evening" keyword
            '1830service.mp3', // "1830" without separator won't match, but matches 18:30 pattern
            '1900_sermon.mp3', // "1900" without separator won't match, but matches 19:00 pattern
        ];

        foreach ($eveningFilenames as $filename) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Evening, $result, "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_time_from_filename_for_service_detection(): void
    {
        // Morning times (before 14:00)
        $morningFilenames = [
            '10-30.mp3' => 'HH-MM format at 10:30',
            '11:45.mkv' => 'HH:MM format at 11:45',
            '09.15.mp4' => 'HH.MM format at 09:15',
            '13-59.mp3' => 'Edge case: 13:59 (last minute of morning)',
        ];

        foreach ($morningFilenames as $filename => $description) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Morning, $result, "Failed for {$description}: {$filename}");
        }

        // Evening times (14:00 and after)
        $eveningFilenames = [
            '14-00.mp3' => 'Edge case: 14:00 (first minute of evening)',
            '18-07.mkv' => 'HH-MM format at 18:07',
            '19:30.mp4' => 'HH:MM format at 19:30',
            '20.45.mp3' => 'HH.MM format at 20:45',
            '23-59.mp3' => 'Edge case: 23:59 (last minute of day)',
        ];

        foreach ($eveningFilenames as $filename => $description) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Evening, $result, "Failed for {$description}: {$filename}");
        }
    }

    #[Test]
    public function it_validates_extracted_time_is_reasonable(): void
    {
        // Invalid times should fall through to other patterns
        $invalidTimes = [
            '25-00.mp3', // Invalid hour
            '12-60.mp3', // Invalid minute
            '30-30.mp3', // Invalid hour
        ];

        foreach ($invalidTimes as $filename) {
            // Should default to morning since no other patterns match
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Morning, $result, "Failed for invalid time: {$filename}");
        }
    }

    #[Test]
    public function it_defaults_to_morning_service_when_no_pattern_found(): void
    {
        $neutralFilenames = [
            'sermon.mp3',
            'sunday_service.mp3',
            'message.mp3',
            'preaching.mp3',
        ];

        foreach ($neutralFilenames as $filename) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals(SermonService::Morning, $result, "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_avoids_false_matches_in_filename_patterns(): void
    {
        // These should not match AM/PM patterns
        $testCases = [
            'example.mp3' => SermonService::Morning, // 'am' in 'example' shouldn't match
            'spam_filter.mp3' => SermonService::Morning, // 'am' in 'spam' shouldn't match
            'team_meeting.mp3' => SermonService::Morning, // 'am' in 'team' shouldn't match
        ];

        foreach ($testCases as $filename => $expected) {
            $result = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals($expected, $result, "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_metadata_from_file_path(): void
    {
        $filePath = '/path/to/2024-01-15_morning_sermon.mp3';

        $result = $this->service->extractFromFilePath($filePath);

        $this->assertInstanceOf(SermonMetadata::class, $result);
        $this->assertEquals('2024-01-15', $result->date->format('Y-m-d'));
        $this->assertEquals(SermonService::Morning, $result->service);
        $this->assertEquals('2024-01-15_morning_sermon.mp3', $result->filename);
        $this->assertEquals('2024-01-15_morning_sermon.mp3', $result->originalName);
    }

    #[Test]
    public function it_guesses_format_from_extension(): void
    {
        $testCases = [
            'mp3' => 'MP3',
            'wav' => 'WAV',
            'flac' => 'FLAC',
            'aac' => 'AAC',
            'm4a' => 'M4A',
            'ogg' => 'OGG',
            'unknown' => 'UNKNOWN',
            '' => null,
        ];

        foreach ($testCases as $extension => $expected) {
            $file = $this->createStub(UploadedFile::class);
            $file->method('getClientOriginalName')->willReturn('test'.($extension ? '.'.$extension : ''));
            $file->method('hashName')->willReturn('hash.mp3');
            $file->method('getClientOriginalExtension')->willReturn($extension);
            $file->method('getSize')->willReturn(1024);

            // Force the underlying library to fail so we hit the fallback code path.
            $file->method('getPathname')->willThrowException(new \Exception('Forced fallback'));

            $metadata = $this->service->extractFromUploadedFile($file);

            $this->assertEquals($expected, $metadata->format, "Failed for extension: {$extension}");
        }
    }

    #[Test]
    public function it_handles_complex_filename_patterns(): void
    {
        $complexFilenames = [
            'CBC_2024-01-15_10.30am_John_3.16-21.mp3' => [
                'date' => '2024-01-15',
                'service' => SermonService::Morning,
            ],
            '15.01.2024_evening_service_Romans_8.mp3' => [
                'date' => '2024-01-15',
                'service' => SermonService::Evening,
            ],
            'Sermon_20240630_PM_1_John_2.1-6.mp3' => [
                'date' => '2024-06-30',
                'service' => SermonService::Evening,
            ],
        ];

        foreach ($complexFilenames as $filename => $expected) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expected['date'], $result->format('Y-m-d'), "Date failed for: {$filename}");

            $service = $this->service->determineServiceFromFilename($filename);
            $this->assertEquals($expected['service'], $service, "Service failed for: {$filename}");
        }
    }

    #[Test]
    public function it_preserves_dates_with_dots_in_filenames(): void
    {
        // These filenames contain dots in Bible references that should not interfere with date extraction
        $testCases = [
            '2024-01-15_John_3.16.mp3' => '2024-01-15',
            '15.01.2024_1_John_2.1-6.mp3' => '2024-01-15',
            'Sermon_2024.03.10_Romans_8.28-39.mp3' => '2024-03-10',
        ];

        foreach ($testCases as $filename => $expectedDate) {
            $result = $this->service->extractDateFromFilename($filename);
            $this->assertEquals($expectedDate, $result->format('Y-m-d'), "Failed for filename: {$filename}");
        }
    }

    #[Test]
    public function it_extracts_embedded_id3_metadata_from_a_file_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'id3-path-test-');

        if ($path === false) {
            self::fail('Failed to allocate a temporary audio file.');
        }

        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00", 256).$this->id3v1Tag(
            title: 'Embedded Title',
            artist: 'Embedded Preacher',
            album: 'Embedded Series',
            year: '2004',
            comment: 'Embedded Reference',
        ));

        try {
            $metadata = $this->service->extractId3MetadataFromPath($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('Embedded Title', $metadata['title']);
        $this->assertSame('Embedded Preacher', $metadata['preacher']);
        $this->assertSame('Embedded Series', $metadata['series']);
        $this->assertSame('Embedded Reference', $metadata['reference']);
        $this->assertSame('2004', $metadata['date']);
    }

    #[Test]
    public function it_handles_edge_cases_gracefully(): void
    {
        // Empty filename
        $result = $this->service->extractDateFromFilename('');
        $this->assertEquals('2026-05-27', $result->format('Y-m-d'));

        // Filename with only extension
        $result = $this->service->extractDateFromFilename('.mp3');
        $this->assertEquals('2026-05-27', $result->format('Y-m-d'));

        // Filename with multiple extensions
        $result = $this->service->extractDateFromFilename('2024-01-15.backup.mp3');
        $this->assertEquals('2024-01-15', $result->format('Y-m-d'));
    }

    private function id3v1Tag(string $title, string $artist, string $album, string $year, string $comment): string
    {
        return 'TAG'
            .$this->id3v1Field($title, 30)
            .$this->id3v1Field($artist, 30)
            .$this->id3v1Field($album, 30)
            .$this->id3v1Field($year, 4)
            .$this->id3v1Field($comment, 30)
            .chr(255);
    }

    private function id3v1Field(string $value, int $length): string
    {
        return str_pad(substr($value, 0, $length), $length, "\0");
    }
}

<?php

namespace Tests\Unit\Services;

use App\Data\SermonCreationOptions;
use App\Enums\TitleGenerationStrategy;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonCreationService;
    }

    /** @test */
    public function it_extracts_date_from_processing_metadata(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [
                'extracted_date' => '2024-03-15',
                'date_extraction_method' => 'video_metadata',
            ],
        ]);

        $date = $this->service->extractDate($log, '2024-01-01-sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    /** @test */
    public function it_extracts_date_from_filename_when_no_metadata(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024-03-15-morning-sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    /** @test */
    public function it_extracts_date_from_filename_with_yyyy_mm_dd_format(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024-12-25-christmas-sermon.mp3');

        $this->assertEquals('2024-12-25', $date);
    }

    /** @test */
    public function it_extracts_date_from_filename_with_dd_mm_yyyy_format(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '25-12-2024-christmas-sermon.mp3');

        $this->assertEquals('2024-12-25', $date);
    }

    /** @test */
    public function it_extracts_date_from_filename_with_underscores(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024_03_15_sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    /** @test */
    public function it_falls_back_to_current_date_when_no_date_found(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, 'sermon-recording.mp3');

        $this->assertEquals(now()->format('Y-m-d'), $date);
    }

    /** @test */
    public function it_detects_evening_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-evening-sermon.mp3');
        $this->assertEquals('evening', $service);

        $service = $this->service->extractServiceType($log, '2024-03-15-EVENING-sermon.mp3');
        $this->assertEquals('evening', $service);
    }

    /** @test */
    public function it_detects_morning_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-morning-sermon.mp3');
        $this->assertEquals('morning', $service);

        $service = $this->service->extractServiceType($log, '2024-03-15-MORNING-sermon.mp3');
        $this->assertEquals('morning', $service);
    }

    /** @test */
    public function it_defaults_to_morning_service_when_not_specified(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-sermon.mp3');
        $this->assertEquals('morning', $service);
    }

    /** @test */
    public function it_uses_extracted_service_from_metadata(): void
    {
        // Test that metadata takes precedence over filename
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [
                'extracted_service' => 'evening',
                'service_extraction_method' => 'file_timestamp',
            ],
        ]);

        // Even though filename says "morning", metadata should win
        $service = $this->service->extractServiceType($log, '2024-03-15-morning-sermon.mp3');
        $this->assertEquals('evening', $service);
    }

    /** @test */
    public function it_generates_unique_slug(): void
    {
        $slug = $this->service->generateUniqueSlug('Test Sermon Title');

        $this->assertEquals('test-sermon-title', $slug);
    }

    /** @test */
    public function it_generates_unique_slug_with_counter_when_duplicate_exists(): void
    {
        // Create existing sermon with slug
        Sermon::factory()->create(['slug' => 'test-sermon']);

        $slug = $this->service->generateUniqueSlug('Test Sermon');

        $this->assertEquals('test-sermon-1', $slug);
    }

    /** @test */
    public function it_generates_unique_slug_with_incrementing_counter(): void
    {
        // Create existing sermons with slugs
        Sermon::factory()->create(['slug' => 'test-sermon']);
        Sermon::factory()->create(['slug' => 'test-sermon-1']);
        Sermon::factory()->create(['slug' => 'test-sermon-2']);

        $slug = $this->service->generateUniqueSlug('Test Sermon');

        $this->assertEquals('test-sermon-3', $slug);
    }

    /** @test */
    public function it_generates_title_from_ai_analysis(): void
    {
        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AI_WITH_FALLBACK,
            [
                'ai_analysis' => ['title' => 'The Grace of God'],
                'filename' => '2024-03-15-sermon.mp3',
            ]
        );

        $this->assertEquals('The Grace of God', $title);
    }

    /** @test */
    public function it_falls_back_to_filename_when_no_ai_title(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AI_WITH_FALLBACK,
            [
                'ai_analysis' => [],
                'filename' => '2024-03-15-faith-and-works.mp3',
                'processing_log' => $log,
            ]
        );

        $this->assertStringContainsString('Faith And Works', $title);
    }

    /** @test */
    public function it_generates_title_from_filename_only(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FILENAME_ONLY,
            [
                'filename' => '2024-03-15-the-power-of-prayer.mp3',
                'processing_log' => $log,
            ]
        );

        $this->assertStringContainsString('The Power Of Prayer', $title);
    }

    /** @test */
    public function it_cleans_filename_when_generating_title(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FILENAME_ONLY,
            [
                'filename' => '2024-03-15-sermon-message-am-test-topic.mp3',
                'processing_log' => $log,
            ]
        );

        // Should remove 'sermon', 'message', 'am' and dates
        $this->assertStringContainsString('Test Topic', $title);
        $this->assertStringNotContainsString('2024-03-15', $title);
        $this->assertStringNotContainsString('sermon', strtolower($title));
        $this->assertStringNotContainsString('message', strtolower($title));
    }

    /** @test */
    public function it_generates_default_title_when_filename_is_too_short(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FILENAME_ONLY,
            [
                'filename' => '2024-03-15.mp3',
                'processing_log' => $log,
                'service' => 'morning',
            ]
        );

        $this->assertStringContainsString('Morning Sermon', $title);
    }

    /** @test */
    public function it_uses_custom_title_when_strategy_is_custom(): void
    {
        $title = $this->service->generateTitle(
            TitleGenerationStrategy::CUSTOM,
            [
                'custom_title' => 'My Custom Sermon Title',
                'filename' => '2024-03-15-ignored.mp3',
            ]
        );

        $this->assertEquals('My Custom Sermon Title', $title);
    }

    /** @test */
    public function it_creates_sermon_from_audio_upload_options(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'audio',
            'source_file_path' => 'audio/test.mp3',
            'original_filename' => '2024-03-15-morning-sermon.mp3',
            'transcript_file_path' => 'transcripts/test.json',
            'processing_metadata' => [],
        ]);

        $aiAnalysis = [
            'title' => 'The Power of Prayer',
            'series' => 'Prayer Series',
            'reference' => 'Matthew 6:5-15',
            'points' => ['Point 1', 'Point 2'],
        ];

        $options = SermonCreationOptions::fromAudioUpload($log, $aiAnalysis);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertInstanceOf(Sermon::class, $sermon);
        $this->assertEquals('The Power of Prayer', $sermon->title);
        $this->assertEquals('audio/test.mp3', $sermon->audio_file_path);
        $this->assertEquals('2024-03-15', $sermon->date->format('Y-m-d'));
        $this->assertEquals('morning', $sermon->service->value);
        $this->assertEquals('Prayer Series', $sermon->series);
        $this->assertEquals('Matthew 6:5-15', $sermon->reference);
        $this->assertEquals('audio_upload', $sermon->source_type);
        $this->assertNotNull($sermon->points);
    }

    /** @test */
    public function it_creates_sermon_from_video_upload_options(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'video',
            'audio_file_path' => 'audio/test.mp3',
            'video_file_path' => 'video/test.mp4',
            'original_filename' => '2024-03-15-evening-sermon.mp4',
            'processing_metadata' => [],
        ]);

        $aiAnalysis = [
            'title' => 'Faith and Works',
        ];

        $options = SermonCreationOptions::fromVideoUpload($log, $aiAnalysis);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertInstanceOf(Sermon::class, $sermon);
        $this->assertEquals('Faith and Works', $sermon->title);
        $this->assertEquals('audio/test.mp3', $sermon->audio_file_path);
        $this->assertEquals('video/test.mp4', $sermon->video_file_path);
        $this->assertEquals('evening', $sermon->service->value);
        $this->assertEquals('video_upload', $sermon->source_type);
    }

    /** @test */
    public function it_creates_sermon_from_livestream_options(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'livestream',
            'audio_file_path' => 'audio/livestream-segment.mp3',
            'original_filename' => '2024-03-15-livestream.mp4',
            'processing_metadata' => [],
        ]);

        $metadata = [
            'source_type' => 'livestream',
            'livestream_processing_id' => 'test-processing-id',
            'original_filename' => '2024-03-15-morning-livestream.mp4',
            'segment_start_time' => 120.5,
            'segment_end_time' => 3600.0,
        ];

        $options = SermonCreationOptions::fromLivestream($log, $metadata);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertInstanceOf(Sermon::class, $sermon);
        $this->assertEquals('audio/livestream-segment.mp3', $sermon->audio_file_path);
        $this->assertEquals('livestream', $sermon->source_type);
        $this->assertEquals('test-processing-id', $sermon->livestream_processing_id);
        $this->assertEquals('morning', $sermon->service->value);
    }

    /** @test */
    public function it_uses_preacher_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: 'audio_upload',
            preacher: 'John Smith',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('John Smith', $sermon->preacher);
    }

    /** @test */
    public function it_uses_default_preacher_when_not_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: 'audio_upload',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('Mark Drury', $sermon->preacher);
    }

    /** @test */
    public function it_uses_date_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: 'audio_upload',
            date: '2024-12-25',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('2024-12-25', $sermon->date->format('Y-m-d'));
    }

    /** @test */
    public function it_uses_service_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-morning-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-morning-sermon.mp3',
            sourceType: 'audio_upload',
            service: 'evening',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('evening', $sermon->service->value);
    }

    /** @test */
    public function it_limits_ai_title_to_100_characters(): void
    {
        $longTitle = str_repeat('Very Long Title ', 20); // Creates a very long title

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AI_WITH_FALLBACK,
            [
                'ai_analysis' => ['title' => $longTitle],
                'filename' => '2024-03-15-sermon.mp3',
            ]
        );

        $this->assertLessThanOrEqual(100, strlen($title));
    }
}

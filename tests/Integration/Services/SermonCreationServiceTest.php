<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonCreationOptions;
use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\TitleGenerationStrategy;
use App\Exceptions\SermonRichnessDowngradeException;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SermonCreationService::class);
    }

    #[Test]
    public function it_extracts_date_from_processing_log_column(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'extracted_date' => '2024-03-15',
            'processing_metadata' => [
                'date_extraction_method' => 'video_metadata',
            ],
        ]);

        $date = $this->service->extractDate($log, '2024-01-01-sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    #[Test]
    public function it_extracts_date_from_filename_when_no_metadata(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024-03-15-morning-sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    #[Test]
    public function it_extracts_date_from_filename_with_yyyy_mm_dd_format(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024-12-25-christmas-sermon.mp3');

        $this->assertEquals('2024-12-25', $date);
    }

    #[Test]
    public function it_extracts_date_from_filename_with_dd_mm_yyyy_format(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '25-12-2024-christmas-sermon.mp3');

        $this->assertEquals('2024-12-25', $date);
    }

    #[Test]
    public function it_extracts_date_from_filename_with_underscores(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, '2024_03_15_sermon.mp3');

        $this->assertEquals('2024-03-15', $date);
    }

    #[Test]
    public function it_falls_back_to_current_date_when_no_date_found(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $date = $this->service->extractDate($log, 'sermon-recording.mp3');

        $this->assertEquals(now()->format('Y-m-d'), $date);
    }

    #[Test]
    public function it_detects_evening_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-evening-sermon.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, '2024-03-15-EVENING-sermon.mp3');
        $this->assertEquals(SermonService::Evening, $service);
    }

    #[Test]
    public function it_detects_morning_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-morning-sermon.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '2024-03-15-MORNING-sermon.mp3');
        $this->assertEquals(SermonService::Morning, $service);
    }

    #[Test]
    public function it_detects_pm_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $service = $this->service->extractServiceType($log, '2024-10-19-pm.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19_pm.mp3');
        $this->assertEquals(SermonService::Evening, $service);
    }

    #[Test]
    public function it_detects_am_service_from_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        $service = $this->service->extractServiceType($log, '2024-10-19-am.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19_am.mp3');
        $this->assertEquals(SermonService::Morning, $service);
    }

    #[Test]
    public function it_detects_evening_service_from_time_in_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        // Test various time formats that indicate evening (>= 14:00)
        $service = $this->service->extractServiceType($log, '2024-10-19-18:00.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19-1830.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19-14:30.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19_18-30.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        $service = $this->service->extractServiceType($log, 'sermon-14:00.mp3');
        $this->assertEquals(SermonService::Evening, $service);
    }

    #[Test]
    public function it_detects_morning_service_from_time_in_filename(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        // Test various time formats that indicate morning (< 14:00)
        $service = $this->service->extractServiceType($log, '2024-10-19-10:00.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19-1030.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19-09:30.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '2024-10-19_08-30.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, 'sermon-06:00.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, 'sermon-12:00.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, 'sermon-13:59.mp3');
        $this->assertEquals(SermonService::Morning, $service);
    }

    #[Test]
    public function it_does_not_confuse_dates_with_times(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        // These should default to morning because the date is not a valid time
        $service = $this->service->extractServiceType($log, '2024-10-19.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        $service = $this->service->extractServiceType($log, '19-10-2024.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        // A June 30 date must not be read as a "6-30" evening time.
        $service = $this->service->extractServiceType($log, '2024-06-30-sermon.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        // A dash-separated time after a date is the real hour, not a date fragment.
        $service = $this->service->extractServiceType($log, '2024-10-19-14-30.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        // A zero-padded morning time stays morning under the 14:00 cutoff.
        $service = $this->service->extractServiceType($log, '2024-10-19-06:30.mp3');
        $this->assertEquals(SermonService::Morning, $service);
    }

    #[Test]
    public function it_prioritizes_explicit_markers_over_time(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
        ]);

        // Even with evening time, "am" marker should take priority
        $service = $this->service->extractServiceType($log, '2024-10-19-18:00-am.mp3');
        $this->assertEquals(SermonService::Morning, $service);

        // Even with morning time, "pm" marker should take priority
        $service = $this->service->extractServiceType($log, '2024-10-19-10:00-pm.mp3');
        $this->assertEquals(SermonService::Evening, $service);

        // "evening" keyword should take priority
        $service = $this->service->extractServiceType($log, '2024-10-19-10:00-evening.mp3');
        $this->assertEquals(SermonService::Evening, $service);
    }

    #[Test]
    public function it_defaults_to_morning_service_when_not_specified(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [], // No extracted service in metadata
        ]);

        $service = $this->service->extractServiceType($log, '2024-03-15-sermon.mp3');
        $this->assertEquals(SermonService::Morning, $service);
    }

    #[Test]
    public function it_uses_extracted_service_from_processing_log_column(): void
    {
        // Test that the column takes precedence over filename
        $log = MediaProcessingLog::factory()->create([
            'extracted_service' => SermonService::Evening,
            'processing_metadata' => [
                'service_extraction_method' => 'file_timestamp',
            ],
        ]);

        // Even though filename says "morning", the column should win
        $service = $this->service->extractServiceType($log, '2024-03-15-morning-sermon.mp3');
        $this->assertEquals(SermonService::Evening, $service);
    }

    #[Test]
    public function it_generates_title_from_ai_analysis(): void
    {
        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AiWithFallback,
            [
                'ai_analysis' => ['title' => 'The Grace of God'],
                'filename' => '2024-03-15-sermon.mp3',
            ]
        );

        $this->assertEquals('The Grace of God', $title);
    }

    #[Test]
    public function it_falls_back_to_filename_when_no_ai_title(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AiWithFallback,
            [
                'ai_analysis' => [],
                'filename' => '2024-03-15-faith-and-works.mp3',
                'processing_log' => $log,
            ]
        );

        $this->assertStringContainsString('Faith And Works', $title);
    }

    #[Test]
    public function it_generates_title_from_filename_only(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FilenameOnly,
            [
                'filename' => '2024-03-15-the-power-of-prayer.mp3',
                'processing_log' => $log,
            ]
        );

        $this->assertStringContainsString('The Power Of Prayer', $title);
    }

    #[Test]
    public function it_cleans_filename_when_generating_title(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FilenameOnly,
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

    #[Test]
    public function it_generates_default_title_when_filename_is_too_short(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $title = $this->service->generateTitle(
            TitleGenerationStrategy::FilenameOnly,
            [
                'filename' => '2024-03-15.mp3',
                'processing_log' => $log,
                'service' => SermonService::Morning,
            ]
        );

        $this->assertStringContainsString('Morning Sermon', $title);
    }

    #[Test]
    public function it_uses_custom_title_when_strategy_is_custom(): void
    {
        $title = $this->service->generateTitle(
            TitleGenerationStrategy::Custom,
            [
                'custom_title' => 'My Custom Sermon Title',
                'filename' => '2024-03-15-ignored.mp3',
            ]
        );

        $this->assertEquals('My Custom Sermon Title', $title);
    }

    #[Test]
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
        $this->assertEquals(SermonService::Morning, $sermon->service);
        $this->assertEquals('Prayer Series', $sermon->series);
        $this->assertEquals('Matthew 6:5-15', $sermon->reference);
        $this->assertEquals(SermonSourceType::AudioUpload, $sermon->source_type);
        $this->assertNotNull($sermon->points);
    }

    #[Test]
    public function it_generates_a_unique_slug_when_creating_a_sermon(): void
    {
        Sermon::factory()->create(['slug' => 'shared-title']);
        Sermon::factory()->create(['slug' => 'shared-title-1']);

        $log = MediaProcessingLog::factory()->audio()->create([
            'extracted_date' => '2024-07-14',
            'extracted_service' => SermonService::Morning,
        ]);
        $options = new SermonCreationOptions(
            audioFilePath: 'audio/shared-title.mp3',
            originalFilename: 'shared-title.mp3',
            sourceType: SermonSourceType::AudioUpload,
            titleStrategy: TitleGenerationStrategy::Custom,
            customTitle: 'Shared Title',
            service: SermonService::Morning,
            date: '2024-07-14',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame('shared-title-2', $sermon->slug);
    }

    #[Test]
    public function it_creates_audio_upload_sermon_using_extracted_historic_identity(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'audio',
            'source_file_path' => 'sermons/audio/2003/08/legacy.mp3',
            'original_filename' => '001a.mp3',
            'extracted_date' => '2003-08-31',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [
                'id3_metadata' => [
                    'title' => 'Preach the Word',
                    'preacher' => 'Bryan Martin',
                    'series' => "The Pastor's Role",
                    'reference' => '2 Timothy 3:14-4:5',
                ],
            ],
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: $log->source_file_path,
            originalFilename: $log->original_filename,
            sourceType: SermonSourceType::AudioUpload,
            service: $log->extracted_service,
            date: $log->extracted_date->toDateString(),
            id3Title: 'Preach the Word',
            id3Preacher: 'Bryan Martin',
            id3Series: "The Pastor's Role",
            id3Reference: '2 Timothy 3:14-4:5',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('Preach the Word', $sermon->title);
        $this->assertEquals('2003-08-31', $sermon->date->format('Y-m-d'));
        $this->assertEquals(SermonService::Morning, $sermon->service);
        $this->assertEquals('Bryan Martin', $sermon->preacher);
        $this->assertEquals("The Pastor's Role", $sermon->series);
        $this->assertEquals('2 Timothy 3:14-4:5', $sermon->reference);
    }

    #[Test]
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
        $this->assertEquals(SermonService::Evening, $sermon->service);
        $this->assertEquals(SermonSourceType::VideoUpload, $sermon->source_type);
    }

    #[Test]
    public function it_creates_sermon_from_livestream_options(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => 'test-processing-id',
            'processing_type' => 'livestream',
            'audio_file_path' => 'audio/livestream-segment.mp3',
            'original_filename' => '17-55.mkv',
            'extracted_date' => '2022-01-16',
            'extracted_service' => SermonService::Evening,
            'processing_metadata' => [],
        ]);

        $metadata = [
            'source_type' => 'livestream',
            'livestream_processing_id' => 'test-processing-id',
            'original_filename' => '17-55.mkv',
            'segment_start_time' => 120.5,
            'segment_end_time' => 3600.0,
        ];

        $options = SermonCreationOptions::fromLivestream($log, $metadata);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertInstanceOf(Sermon::class, $sermon);
        $this->assertEquals('Evening Sermon - January 16, 2022', $sermon->title);
        $this->assertEquals('audio/livestream-segment.mp3', $sermon->audio_file_path);
        $this->assertEquals(SermonSourceType::Livestream, $sermon->source_type);
        $this->assertEquals('test-processing-id', $sermon->livestream_processing_id);
        $this->assertEquals('2022-01-16', $sermon->date->toDateString());
        $this->assertEquals(SermonService::Evening, $sermon->service);
    }

    #[Test]
    public function it_uses_id3_preacher_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: SermonSourceType::AudioUpload,
            id3Preacher: 'John Smith',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('John Smith', $sermon->preacher);
        $this->assertEquals(PreacherSource::Id3, $sermon->preacher_source);
        $this->assertFalse($sermon->needs_preacher_review);
        $this->assertNotNull($sermon->preacher_id);
    }

    #[Test]
    public function it_defaults_to_visiting_speaker_when_preacher_not_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: SermonSourceType::AudioUpload,
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('Visiting Speaker', $sermon->preacher);
        $this->assertEquals(PreacherSource::Default, $sermon->preacher_source);
        $this->assertTrue($sermon->needs_preacher_review);
        $this->assertNotNull($sermon->preacher_id);
    }

    #[Test]
    public function it_defaults_to_visiting_speaker_when_id3_preacher_is_blank(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: SermonSourceType::AudioUpload,
            id3Preacher: '   ',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('Visiting Speaker', $sermon->preacher);
        $this->assertEquals(PreacherSource::Default, $sermon->preacher_source);
        $this->assertTrue($sermon->needs_preacher_review);
        $this->assertNotNull($sermon->preacher_id);
    }

    #[Test]
    public function it_uses_an_explicit_preacher_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $preacher = Preacher::factory()->create(['name' => 'Mary Helper']);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: SermonSourceType::Livestream,
            preacher: $preacher->name,
            preacherId: $preacher->id,
            preacherSource: PreacherSource::Manual,
            needsPreacherReview: false,
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals($preacher->name, $sermon->preacher);
        $this->assertEquals(PreacherSource::Manual, $sermon->preacher_source);
        $this->assertFalse($sermon->needs_preacher_review);
    }

    #[Test]
    public function it_uses_date_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-sermon.mp3',
            sourceType: SermonSourceType::AudioUpload,
            date: '2024-12-25',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals('2024-12-25', $sermon->date->format('Y-m-d'));
    }

    #[Test]
    public function it_uses_service_override_when_provided(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [],
            'original_filename' => '2024-03-15-morning-sermon.mp3',
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/test.mp3',
            originalFilename: '2024-03-15-morning-sermon.mp3',
            sourceType: SermonSourceType::AudioUpload,
            service: SermonService::Evening,
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertEquals(SermonService::Evening, $sermon->service);
    }

    #[Test]
    public function it_passes_ai_title_through_without_truncating(): void
    {
        $title = $this->service->generateTitle(
            TitleGenerationStrategy::AiWithFallback,
            [
                'ai_analysis' => ['title' => 'A perfectly valid sermon title from AI'],
                'filename' => '2024-03-15-sermon.mp3',
            ]
        );

        $this->assertEquals('A perfectly valid sermon title from AI', $title);
    }

    #[Test]
    public function upsert_enriches_audio_only_sermon_with_incoming_livestream(): void
    {
        $existing = Sermon::factory()->create([
            'date' => '2023-05-21',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'audio/legacy.mp3',
            'video_file_path' => null,
            'livestream_processing_id' => null,
            'transcript_file_path' => null,
            'series' => null,
            'reference' => null,
            'source_type' => SermonSourceType::AudioUpload,
            'preacher' => 'Visiting Speaker',
            'preacher_source' => PreacherSource::Default,
            'needs_preacher_review' => true,
        ]);
        $originalSlug = $existing->slug;
        $originalTitle = $existing->title;

        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2023-05-21',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [],
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'sermons/audio/livestream.mp3',
            originalFilename: 'livestream.mkv',
            sourceType: SermonSourceType::Livestream,
            videoFilePath: 'sermons/video/livestream.mp4',
            transcriptFilePath: 'transcripts/livestream.json',
            aiAnalysis: ['series' => 'Romans', 'reference' => 'Romans 8:28', 'points' => ['P1', 'P2']],
            livestreamProcessingId: $log->processing_id,
            segmentStartTime: 100.0,
            segmentEndTime: 2700.0,
            service: SermonService::Morning,
            date: '2023-05-21',
            id3Preacher: 'Pastor Verified',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame($existing->id, $sermon->id);
        $this->assertSame($originalSlug, $sermon->slug);
        $this->assertSame($originalTitle, $sermon->title);
        $this->assertSame('audio/legacy.mp3', $sermon->audio_file_path);
        $this->assertSame('sermons/video/livestream.mp4', $sermon->video_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
        $this->assertSame('Romans', $sermon->series);
        $this->assertSame('Romans 8:28', $sermon->reference);
        $this->assertSame(SermonSourceType::Livestream, $sermon->source_type);
        $this->assertSame('Pastor Verified', $sermon->preacher);
        $this->assertSame(PreacherSource::Id3, $sermon->preacher_source);
        $this->assertSame(1, Sermon::query()->where('date', '2023-05-21')->where('service', SermonService::Morning->value)->where('content_type', SermonContentType::Sermon->value)->count());
    }

    #[Test]
    public function upsert_preserves_manual_fields_when_enriching(): void
    {
        Sermon::factory()->create([
            'date' => '2023-05-21',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'audio/legacy.mp3',
            'video_file_path' => null,
            'livestream_processing_id' => null,
            'series' => 'Manually Set Series',
            'reference' => 'Manually Set Reference',
            'source_type' => SermonSourceType::AudioUpload,
            'preacher' => 'Manually Set Preacher',
            'preacher_source' => PreacherSource::Manual,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2023-05-21',
            'extracted_service' => SermonService::Morning,
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'sermons/audio/livestream.mp3',
            originalFilename: 'livestream.mkv',
            sourceType: SermonSourceType::Livestream,
            videoFilePath: 'sermons/video/livestream.mp4',
            aiAnalysis: ['series' => 'AI Series', 'reference' => 'AI Reference'],
            livestreamProcessingId: $log->processing_id,
            service: SermonService::Morning,
            date: '2023-05-21',
            id3Preacher: 'AI Preacher',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame('Manually Set Series', $sermon->series);
        $this->assertSame('Manually Set Reference', $sermon->reference);
        $this->assertSame('Manually Set Preacher', $sermon->preacher);
        $this->assertSame(PreacherSource::Manual, $sermon->preacher_source);
        $this->assertSame('sermons/video/livestream.mp4', $sermon->video_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
    }

    #[Test]
    public function upsert_replaces_audio_only_sermon_when_incoming_is_also_audio(): void
    {
        $existing = Sermon::factory()->create([
            'date' => '2024-01-01',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'audio/old.mp3',
            'video_file_path' => null,
            'livestream_processing_id' => null,
            'source_type' => SermonSourceType::AudioUpload,
        ]);

        $log = MediaProcessingLog::factory()->audio()->create([
            'extracted_date' => '2024-01-01',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [],
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/new.mp3',
            originalFilename: 'replacement.mp3',
            sourceType: SermonSourceType::AudioUpload,
            service: SermonService::Morning,
            date: '2024-01-01',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame($existing->id, $sermon->id);
        $this->assertSame('audio/new.mp3', $sermon->audio_file_path);
        $this->assertSame(1, Sermon::query()->where('date', '2024-01-01')->where('service', SermonService::Morning->value)->where('content_type', SermonContentType::Sermon->value)->count());
    }

    #[Test]
    public function upsert_rejects_audio_incoming_when_existing_is_livestream(): void
    {
        $existingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        Sermon::factory()->create([
            'date' => '2024-05-12',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'audio/from-livestream.mp3',
            'video_file_path' => 'video/from-livestream.mp4',
            'livestream_processing_id' => $existingLog->processing_id,
            'source_type' => SermonSourceType::Livestream,
        ]);

        $log = MediaProcessingLog::factory()->audio()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/downgrade.mp3',
            originalFilename: 'downgrade.mp3',
            sourceType: SermonSourceType::AudioUpload,
            service: SermonService::Morning,
            date: '2024-05-12',
        );

        $this->expectException(SermonRichnessDowngradeException::class);
        $this->service->createSermon($log, $options);
    }

    #[Test]
    public function upsert_rejects_video_incoming_when_existing_is_livestream(): void
    {
        $existingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Evening,
        ]);

        Sermon::factory()->create([
            'date' => '2024-05-12',
            'service' => SermonService::Evening,
            'content_type' => SermonContentType::Sermon,
            'video_file_path' => 'video/from-livestream.mp4',
            'livestream_processing_id' => $existingLog->processing_id,
            'source_type' => SermonSourceType::Livestream,
        ]);

        $log = MediaProcessingLog::factory()->video()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Evening,
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/video-upload.mp3',
            originalFilename: 'downgrade.mp4',
            sourceType: SermonSourceType::VideoUpload,
            videoFilePath: 'video/downgrade.mp4',
            service: SermonService::Evening,
            date: '2024-05-12',
        );

        $this->expectException(SermonRichnessDowngradeException::class);
        $this->service->createSermon($log, $options);
    }

    #[Test]
    public function upsert_force_overwrite_bypasses_reject_path(): void
    {
        $existingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        $existing = Sermon::factory()->create([
            'date' => '2024-05-12',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'audio/old.mp3',
            'video_file_path' => 'video/from-livestream.mp4',
            'livestream_processing_id' => $existingLog->processing_id,
            'source_type' => SermonSourceType::Livestream,
        ]);

        $log = MediaProcessingLog::factory()->audio()->create([
            'extracted_date' => '2024-05-12',
            'extracted_service' => SermonService::Morning,
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/forced.mp3',
            originalFilename: 'forced.mp3',
            sourceType: SermonSourceType::AudioUpload,
            service: SermonService::Morning,
            date: '2024-05-12',
            forceOverwrite: true,
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame($existing->id, $sermon->id);
        $this->assertSame('audio/forced.mp3', $sermon->audio_file_path);
    }

    #[Test]
    public function upsert_isolates_childrens_talk_from_main_sermon_at_same_date_and_service(): void
    {
        Sermon::factory()->create([
            'date' => '2024-06-02',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'audio/childrens-talk.mp3',
            'source_type' => SermonSourceType::Livestream,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2024-06-02',
            'extracted_service' => SermonService::Morning,
        ]);

        $options = new SermonCreationOptions(
            audioFilePath: 'audio/main-sermon.mp3',
            originalFilename: 'main-sermon.mkv',
            sourceType: SermonSourceType::Livestream,
            videoFilePath: 'video/main-sermon.mp4',
            livestreamProcessingId: $log->processing_id,
            service: SermonService::Morning,
            date: '2024-06-02',
        );

        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame(SermonContentType::Sermon, $sermon->content_type);
        $this->assertSame(2, Sermon::query()->where('date', '2024-06-02')->where('service', SermonService::Morning->value)->count());
        $this->assertSame(
            1,
            Sermon::query()
                ->where('date', '2024-06-02')
                ->where('service', SermonService::Morning->value)
                ->where('content_type', SermonContentType::ChildrensTalk->value)
                ->count()
        );
    }

    /**
     * F44: without the curated facts the filename strategy leaves this sermon
     * titled only "Morning", which is the loss the readiness plan describes.
     */
    #[Test]
    public function curated_historic_facts_outrank_the_filename_title(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'audio_file_path' => 'sermons/audio/2019-10-06.mp3',
            'original_filename' => '2019-10-06 Morning.mkv',
            'extracted_date' => '2019-10-06',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [
                'historic_import' => [
                    'tag' => 'livestream',
                    'editorial_facts' => [
                        'occasion' => 'Harvest Thanksgiving',
                        'title' => 'The God Who Provides',
                        'speaker' => 'Alan Brown',
                        'scripture_reference' => 'Ruth 2:1-23',
                        'series' => 'Ruth',
                    ],
                ],
            ],
        ]);

        $options = SermonCreationOptions::fromLivestream($log, []);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame('The God Who Provides', $sermon->title);
        $this->assertSame('Ruth', $sermon->series);
        $this->assertSame('Ruth 2:1-23', $sermon->reference);
        $this->assertSame('Alan Brown', $sermon->preacher);
        $this->assertSame(PreacherSource::Manual, $sermon->preacher_source);
        $this->assertFalse($sermon->needs_preacher_review);
    }

    #[Test]
    public function curated_facts_outrank_id3_and_ai_analysis(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'audio',
            'source_file_path' => 'sermons/audio/2019-10-06.mp3',
            'original_filename' => 'harvest.mp3',
            'extracted_date' => '2019-10-06',
            'extracted_service' => SermonService::Morning,
            'processing_metadata' => [
                'id3_metadata' => [
                    'title' => 'Track 04',
                    'series' => 'Unsorted',
                    'reference' => 'Ruth 1',
                ],
                'historic_import' => [
                    'editorial_facts' => [
                        'occasion' => null,
                        'title' => 'The God Who Provides',
                        'speaker' => null,
                        'scripture_reference' => 'Ruth 2:1-23',
                        'series' => 'Ruth',
                    ],
                ],
            ],
        ]);

        $options = SermonCreationOptions::fromAudioUpload($log, [
            'title' => 'An AI Guess',
            'series' => 'AI Series',
            'reference' => 'Ruth 3',
            'points' => [],
            'summary' => null,
            'transcript' => '',
        ]);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame('The God Who Provides', $sermon->title);
        $this->assertSame('Ruth', $sermon->series);
        $this->assertSame('Ruth 2:1-23', $sermon->reference);
    }

    #[Test]
    public function an_ordinary_run_is_unaffected_by_the_curated_fact_path(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => 'audio',
            'source_file_path' => 'audio/ordinary.mp3',
            'original_filename' => '2024-03-15-morning-sermon.mp3',
            'processing_metadata' => [],
        ]);

        $options = SermonCreationOptions::fromAudioUpload($log, [
            'title' => 'The Power of Prayer',
            'series' => 'Prayer Series',
            'reference' => 'Matthew 6:5-15',
            'points' => [],
            'summary' => null,
            'transcript' => '',
        ]);
        $sermon = $this->service->createSermon($log, $options);

        $this->assertSame('The Power of Prayer', $sermon->title);
        $this->assertSame('Prayer Series', $sermon->series);
        $this->assertSame('Matthew 6:5-15', $sermon->reference);
    }
}

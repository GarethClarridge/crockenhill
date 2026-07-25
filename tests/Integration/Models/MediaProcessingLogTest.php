<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Data\ProcessingMetadata;
use App\Data\SermonAnalysis;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function media_type_factory_states_create_matching_file_paths(): void
    {
        $audio = MediaProcessingLog::factory()->audio()->make();
        $video = MediaProcessingLog::factory()->video()->make();
        $livestream = MediaProcessingLog::factory()->livestream()->make();

        $this->assertSame(MediaType::Audio, $audio->processing_type);
        $this->assertStringStartsWith('sermons/test/', (string) $audio->source_file_path);
        $this->assertStringEndsWith('.mp3', (string) $audio->source_file_path);
        $this->assertNull($audio->audio_file_path);

        $this->assertSame(MediaType::Video, $video->processing_type);
        $this->assertStringStartsWith('videos/test/', (string) $video->source_file_path);
        $this->assertStringEndsWith('.mp4', (string) $video->source_file_path);
        $this->assertStringStartsWith('sermons/test/', (string) $video->audio_file_path);
        $this->assertStringEndsWith('.mp3', (string) $video->audio_file_path);

        $this->assertSame(MediaType::Livestream, $livestream->processing_type);
        $this->assertStringStartsWith('livestreams/test/', (string) $livestream->source_file_path);
        $this->assertStringEndsWith('.mp4', (string) $livestream->source_file_path);
        $this->assertStringStartsWith('sermons/test/', (string) $livestream->audio_file_path);
        $this->assertStringEndsWith('.mp3', (string) $livestream->audio_file_path);
    }

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $data = [
            'processing_id' => 'uuid-123',
            'processing_type' => MediaType::Audio,
            'status' => ProcessingStatus::Processing,
            'current_step' => 'initialization',
            'error_message' => 'Something went wrong',
            'original_filename' => 'test.mp3',
            'file_size' => 1024,
            'duration' => 60.5,
            'source_file_path' => 'path/to/file',
            'audio_file_path' => 'path/to/audio',
            'video_file_path' => 'path/to/video',
            'transcript_file_path' => 'path/to/transcript',
            'rms_log_path' => 'path/to/rms',
            'sermon_start_time' => 10.0,
            'sermon_end_time' => 50.0,
            'ai_analysis' => [
                'title' => 'Example Sermon',
                'series' => 'Test Series',
                'reference' => 'John 3:16',
                'points' => ['Point 1'],
                'summary' => 'Summary',
                'transcript' => str_repeat('Transcript ', 20),
            ],
            'processing_metadata' => ['meta' => 'data'],
            'threshold_method' => 'adaptive',
            'adaptive_threshold' => -20.0,
            'rms_stats' => ['avg' => -25],
            'sermon_id' => 1,
            'church_service_id' => 2,
            'started_at' => now(),
            'completed_at' => now(),
        ];

        $log = new MediaProcessingLog($data);

        foreach ($data as $key => $value) {
            if (in_array($key, ['started_at', 'completed_at'])) {
                continue;
            }

            if ($key === 'ai_analysis') {
                $this->assertInstanceOf(SermonAnalysis::class, $log->ai_analysis);
                $this->assertEquals($value, $log->ai_analysis?->toArray());

                continue;
            }

            if ($key === 'processing_metadata') {
                $this->assertInstanceOf(ProcessingMetadata::class, $log->processing_metadata);
                $this->assertEquals($value, $log->processing_metadata?->toArray());

                continue;
            }

            $this->assertEquals($value, $log->$key);
        }
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => 'processing',
            'ai_analysis' => [
                'title' => 'Stored Title',
                'series' => 'Series',
                'reference' => 'Psalm 23',
                'points' => ['Hope'],
                'summary' => 'Summary',
                'transcript' => str_repeat('Transcript ', 20),
            ],
            'processing_metadata' => ['step' => 1],
            'duration' => '60.5',
            'started_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertInstanceOf(ProcessingStatus::class, $log->status);
        $this->assertEquals(ProcessingStatus::Processing, $log->status);
        $this->assertInstanceOf(SermonAnalysis::class, $log->ai_analysis);
        $this->assertInstanceOf(ProcessingMetadata::class, $log->processing_metadata);
        $this->assertIsFloat($log->duration);
        $this->assertInstanceOf(Carbon::class, $log->started_at);
    }

    #[Test]
    public function it_wraps_processing_metadata_without_losing_historical_keys(): void
    {
        $processingMetadata = [
            'id3_metadata' => [
                'title' => 'ID3 Title',
                'preacher' => 'Mark Drury',
                'series' => 'Romans',
                'reference' => 'Romans 8',
            ],
            'manual_review' => [
                'status' => 'required',
                'reason_code' => 'ratio_below_threshold',
                'reason_message' => 'Needs review',
                'flagged_at' => '2026-03-17T12:00:00+00:00',
                'speech_segments' => [
                    ['segment_id' => 3, 'start_time' => 10.0, 'end_time' => 20.0, 'duration' => 10.0],
                ],
            ],
            'speaker_identification' => [
                'outcome' => 'matched',
            ],
            'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            'trim_requested' => true,
            'legacy_context' => 'preserve-me',
        ];

        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => $processingMetadata,
        ]);

        $this->assertSame('ID3 Title', $log->processing_metadata->id3Metadata?->title);
        $this->assertSame('ratio_below_threshold', $log->processing_metadata?->manualReview?->reasonCode);
        $this->assertSame(MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM, $log->processing_metadata?->videoProcessingMode);
        $this->assertTrue($log->processing_metadata?->trimRequested);
        $this->assertSame($processingMetadata, $log->processing_metadata?->toArray());
    }

    #[Test]
    public function it_derives_auto_trim_pipeline_helpers_from_processing_metadata(): void
    {
        $autoTrimVideo = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $fullVideo = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
            ],
        ]);

        $livestream = MediaProcessingLog::factory()->livestream()->create();

        $this->assertSame(MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM, $autoTrimVideo->videoProcessingMode());
        $this->assertTrue($autoTrimVideo->isAutoTrimVideoRun());
        $this->assertTrue($autoTrimVideo->usesSegmentationPipeline());
        $this->assertSame('video_auto_trim', $autoTrimVideo->processingPipelineProfile());
        $this->assertTrue($autoTrimVideo->canUseManualSermonReview());

        $this->assertSame(MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO, $fullVideo->videoProcessingMode());
        $this->assertFalse($fullVideo->isAutoTrimVideoRun());
        $this->assertFalse($fullVideo->usesSegmentationPipeline());
        $this->assertSame('video', $fullVideo->processingPipelineProfile());
        $this->assertFalse($fullVideo->canUseManualSermonReview());

        $this->assertTrue($livestream->usesSegmentationPipeline());
        $this->assertSame('livestream', $livestream->processingPipelineProfile());
    }

    #[Test]
    public function it_builds_dedup_keys_with_caller_scope_and_processing_profile(): void
    {
        $owner = User::factory()->create();
        $hash = str_repeat('a', 64);
        $autoTrimLog = MediaProcessingLog::factory()->video()->make([
            'file_hash' => $hash,
            'owner_user_id' => $owner->id,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);
        $systemLog = MediaProcessingLog::factory()->audio()->make([
            'file_hash' => $hash,
            'owner_user_id' => null,
        ]);

        $this->assertSame(
            "{$hash}:user:{$owner->id}:video:auto_trim",
            $autoTrimLog->buildDedupKey()
        );
        $this->assertSame(
            "{$hash}:system:audio:full_video",
            $systemLog->buildDedupKey()
        );
    }

    #[Test]
    public function it_has_relationships(): void
    {
        $sermon = Sermon::factory()->create();
        $churchService = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'church_service_id' => $churchService->id,
        ]);
        LivestreamSegment::factory()
            ->count(3)
            ->sequence(fn ($sequence) => ['segment_index' => $sequence->index + 1])
            ->create(['media_processing_log_id' => $log->id]);

        $this->assertInstanceOf(Sermon::class, $log->sermon);
        $this->assertTrue($log->churchService->is($churchService));
        $this->assertCount(3, $log->segments);
    }

    #[Test]
    public function it_defines_type_scopes(): void
    {
        $audio = MediaProcessingLog::factory()->create(['processing_type' => 'audio']);
        $video = MediaProcessingLog::factory()->create(['processing_type' => 'video']);
        $livestream = MediaProcessingLog::factory()->create(['processing_type' => 'livestream']);

        $ids = [$audio->id, $video->id, $livestream->id];

        $this->assertSame([$audio->id], MediaProcessingLog::audio()->whereKey($ids)->pluck('id')->all());
        $this->assertSame([$video->id], MediaProcessingLog::video()->whereKey($ids)->pluck('id')->all());
        $this->assertSame([$livestream->id], MediaProcessingLog::livestream()->whereKey($ids)->pluck('id')->all());
    }

    #[Test]
    public function it_preserves_durable_rms_logs_while_including_temp_video_paths(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->make([
            'source_file_path' => 'livestream/temp/original.mkv',
            'enhanced_audio_file_path' => '/tmp/enhanced.mp3',
            'video_file_path' => 'temp/sermon-video.mp4',
            'rms_log_path' => 'temp/rms.log',
            'processing_metadata' => [
                'temp_video_path' => 'temp/segment-video.mp4',
            ],
        ]);

        $this->assertSame([
            'livestream/temp/original.mkv',
            '/tmp/enhanced.mp3',
            'temp/sermon-video.mp4',
            'temp/segment-video.mp4',
        ], $log->temporaryFilePaths());
    }

    #[Test]
    public function it_defines_status_scopes(): void
    {
        $processing = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Processing]);
        $pending = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Pending]);
        $completed = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Completed]);
        $failed = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Failed]);

        $ids = [$processing->id, $pending->id, $completed->id, $failed->id];

        $this->assertSame([$processing->id], MediaProcessingLog::processing()->whereKey($ids)->pluck('id')->all());
        $this->assertSame([$pending->id], MediaProcessingLog::pending()->whereKey($ids)->pluck('id')->all());
        $this->assertSame([$completed->id], MediaProcessingLog::completed()->whereKey($ids)->pluck('id')->all());
        $this->assertSame([$failed->id], MediaProcessingLog::failed()->whereKey($ids)->pluck('id')->all());
    }

    #[Test]
    public function it_provides_status_helpers(): void
    {
        $log = new MediaProcessingLog(['status' => ProcessingStatus::Completed]);

        $this->assertTrue($log->isComplete());
        $this->assertFalse($log->isFailed());
        $this->assertFalse($log->isProcessing());
        $this->assertFalse($log->isPending());
    }

    #[Test]
    public function it_manages_status_transitions(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Pending]);

        app(MediaProcessingRunTransitionService::class)->markAsProcessing($log, 'step1');
        $this->assertEquals(ProcessingStatus::Processing, $log->status);
        $this->assertEquals('step1', $log->current_step);
        $this->assertNotNull($log->started_at);

        app(MediaProcessingRunTransitionService::class)->markAsCompleted($log);
        $this->assertEquals(ProcessingStatus::Completed, $log->status);
        $this->assertNotNull($log->completed_at);

        app(MediaProcessingRunTransitionService::class)->markAsFailed($log, 'Error occurred', 'step2');
        $this->assertEquals(ProcessingStatus::Failed, $log->status);
        $this->assertEquals('Error occurred', $log->error_message);
        $this->assertEquals('step2', $log->current_step);
    }

    #[Test]
    public function it_can_preserve_completion_context_when_marked_as_completed(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'notification_failed',
            'error_message' => 'Notification failed: SMTP transport unavailable',
        ]);

        app(MediaProcessingRunTransitionService::class)->markAsCompleted(
            $log,
            step: 'notification_failed',
            errorMessage: 'Notification failed: SMTP transport unavailable'
        );

        $this->assertEquals(ProcessingStatus::Completed, $log->status);
        $this->assertEquals('notification_failed', $log->current_step);
        $this->assertEquals(
            'Notification failed: SMTP transport unavailable',
            $log->error_message
        );
        $this->assertNotNull($log->completed_at);
    }

    #[Test]
    public function it_returns_false_and_preserves_cancelled_status_when_attempting_to_mark_cancelled_run_as_completed(): void
    {
        $log = MediaProcessingLog::factory()->cancelled()->create();

        $result = app(MediaProcessingRunTransitionService::class)->markAsCompleted($log);

        $this->assertFalse($result);
        $log->refresh();
        $this->assertEquals(ProcessingStatus::Cancelled, $log->status);
        $this->assertEquals('cancelled', $log->current_step);
        $this->assertNull($log->completed_at);
    }

    #[Test]
    public function it_stores_structured_metadata_when_marked_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Processing]);

        $speechSegments = [
            ['segment_id' => 1, 'start_time' => 0.0, 'end_time' => 1320.0, 'duration' => 1320.0],
            ['segment_id' => 2, 'start_time' => 1400.0, 'end_time' => 2300.0, 'duration' => 900.0],
        ];

        app(MediaProcessingRunTransitionService::class)
            ->markForManualReview($log, 'ratio_below_threshold', 'The longest speech block was not at least 1.5x longer.', $speechSegments);
        $log->refresh();

        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertSame('The longest speech block was not at least 1.5x longer.', $log->error_message);

        $review = $log->manualReviewMetadata();
        $this->assertSame('required', $review['status']);
        $this->assertSame('ratio_below_threshold', $review['reason_code']);
        $this->assertSame('The longest speech block was not at least 1.5x longer.', $review['reason_message']);
        $this->assertNotNull($review['flagged_at']);
        $this->assertCount(2, $review['speech_segments']);
    }

    #[Test]
    public function it_confirms_manual_review_without_rehydrating_unmodelled_keys(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'ratio_below_threshold',
                    'reason_message' => 'Needs review',
                    'flagged_at' => '2026-03-17T12:00:00+00:00',
                    'speech_segments' => [
                        ['segment_id' => 3, 'start_time' => 10.0, 'end_time' => 20.0, 'duration' => 10.0],
                    ],
                    'legacy_extra_field' => 'should-not-survive-confirmation',
                ],
            ],
        ]);

        app(MediaProcessingRunTransitionService::class)
            ->confirmSermonSegment($log, 3, 7);

        $log->refresh();

        $review = $log->manualReviewMetadata();

        $this->assertSame('confirmed', $review['status']);
        $this->assertSame(3, $review['confirmed_segment_id']);
        $this->assertSame(7, $review['confirmed_by_user_id']);
        $this->assertArrayNotHasKey('legacy_extra_field', $review);
    }

    #[Test]
    public function it_requires_manual_sermon_review_when_status_is_required(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $this->assertFalse($log->requiresManualSermonReview());

        app(MediaProcessingRunTransitionService::class)
            ->markForManualReview($log, 'no_qualifying_speech_block', 'No speech block met the threshold.');
        $log->refresh();

        $this->assertTrue($log->requiresManualSermonReview());
    }

    #[Test]
    public function it_detects_legacy_manual_review_rows_as_awaiting_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Manual Review Note: Multiple speech blocks met the 20-minute sermon threshold.',
            'processing_metadata' => null,
        ]);

        $this->assertTrue($log->requiresManualSermonReview());
        $this->assertSame('multiple_qualifying_speech_blocks', $log->manualReviewMetadata()['reason_code']);
    }

    #[Test]
    public function it_includes_legacy_manual_review_rows_in_the_awaiting_review_scope(): void
    {
        $legacyLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Manual Review Note: Sermon auto-selection confidence was insufficient.',
            'processing_metadata' => null,
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Unrelated failure message',
            'processing_metadata' => null,
        ]);

        $matchingIds = MediaProcessingLog::query()
            ->awaitingManualSermonReview()
            ->pluck('id')
            ->all();

        $this->assertContains($legacyLog->id, $matchingIds);
    }

    #[Test]
    public function it_returns_null_for_confirmed_segment_id_before_confirmation(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $this->assertNull($log->manuallyConfirmedSegmentId());
    }

    #[Test]
    public function it_confirms_sermon_segment_and_records_audit_data(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Failed]);
        app(MediaProcessingRunTransitionService::class)
            ->markForManualReview($log, 'ratio_below_threshold', 'Ratio below threshold.');
        $log->refresh();

        app(MediaProcessingRunTransitionService::class)->confirmSermonSegment($log, 42, 7);
        $log->refresh();

        $this->assertSame(ProcessingStatus::Pending, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertNull($log->error_message);
        $this->assertSame(42, $log->manuallyConfirmedSegmentId());

        $review = $log->manualReviewMetadata();
        $this->assertSame('confirmed', $review['status']);
        $this->assertSame(42, $review['confirmed_segment_id']);
        $this->assertSame(7, $review['confirmed_by_user_id']);
        $this->assertNotNull($review['confirmed_at']);
        $this->assertFalse($log->requiresManualSermonReview());
    }

    #[Test]
    public function it_preserves_existing_manual_review_metadata_when_confirming(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $speechSegments = [['segment_id' => 10, 'start_time' => 0.0, 'end_time' => 1200.0, 'duration' => 1200.0]];
        app(MediaProcessingRunTransitionService::class)
            ->markForManualReview($log, 'no_qualifying_speech_block', 'No qualifying block.', $speechSegments);
        $log->refresh();

        app(MediaProcessingRunTransitionService::class)->confirmSermonSegment($log, 10, 1);
        $log->refresh();

        $review = $log->manualReviewMetadata();
        $this->assertSame('no_qualifying_speech_block', $review['reason_code']);
        $this->assertCount(1, $review['speech_segments']);
        $this->assertSame(10, $review['confirmed_segment_id']);
    }

    #[Test]
    public function source_video_check_is_delegated_to_the_storage_service(): void
    {
        // Confirms the model no longer owns I/O: the equivalent check lives in
        // VideoStorageService::sourceVideoExistsForPath() (TD-043).
        $this->assertFalse(
            method_exists(MediaProcessingLog::class, 'sourceVideoExists'),
            'sourceVideoExists() must not exist on MediaProcessingLog — it was moved to VideoStorageService.'
        );
    }

    #[Test]
    public function it_has_backward_compatible_stored_file_path(): void
    {
        $log = new MediaProcessingLog;
        $log->stored_file_path = 'test-path';

        $this->assertEquals('test-path', $log->source_file_path);
        $this->assertEquals('test-path', $log->stored_file_path);
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Cancelled]);

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($log, 'Should be ignored');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $log->status);
        $this->assertNotEquals('Should be ignored', $log->error_message);
    }

    #[Test]
    public function mark_as_completed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Cancelled]);

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsCompleted($log, 'completed');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $log->status);
    }

    #[Test]
    public function mark_as_processing_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Cancelled]);

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsProcessing($log, 'some_step');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $log->status);
    }
}

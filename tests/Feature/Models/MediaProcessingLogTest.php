<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_identifies_completed_status(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Completed]);
        $this->assertTrue($log->isComplete());
        $this->assertFalse($log->isFailed());
    }

    #[Test]
    public function it_identifies_failed_status(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Failed]);
        $this->assertTrue($log->isFailed());
        $this->assertFalse($log->isComplete());
    }

    #[Test]
    public function it_identifies_processing_status(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Processing]);
        $this->assertTrue($log->isProcessing());
        $this->assertFalse($log->isPending());
    }

    #[Test]
    public function it_identifies_pending_status(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Pending]);
        $this->assertTrue($log->isPending());
        $this->assertFalse($log->isProcessing());
    }

    #[Test]
    public function it_identifies_cancelled_status(): void
    {
        $log = MediaProcessingLog::factory()->create(['status' => ProcessingStatus::Cancelled]);
        $this->assertTrue($log->isCancelled());
    }

    #[Test]
    public function it_identifies_degraded_completion(): void
    {
        $log = MediaProcessingLog::factory()->create(['is_degraded_completion' => true]);
        $this->assertTrue($log->isDegradedCompletion());

        $log2 = MediaProcessingLog::factory()->create(['is_degraded_completion' => false]);
        $this->assertFalse($log2->isDegradedCompletion());
    }

    #[Test]
    public function it_detects_when_manual_sermon_review_is_required_via_metadata(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'no_qualifying_speech_block',
                ],
            ],
        ]);

        $this->assertTrue($log->requiresManualSermonReview());
        $this->assertEquals('required', $log->manualReviewMetadata()['status']);
        $this->assertEquals('no_qualifying_speech_block', $log->manualReviewMetadata()['reason_code']);
    }

    #[Test]
    public function it_detects_when_manual_sermon_review_is_required_via_legacy_error_message(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'error_message' => 'Manual Review Note: No speech block met the 20-minute sermon threshold.',
            'processing_metadata' => null,
        ]);

        $this->assertTrue($log->requiresManualSermonReview());
        $this->assertEquals('required', $log->manualReviewMetadata()['status']);
        $this->assertEquals('no_qualifying_speech_block', $log->manualReviewMetadata()['reason_code']);
    }

    #[Test]
    public function manual_review_is_not_required_if_already_confirmed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'confirmed',
                ],
            ],
        ]);

        $this->assertFalse($log->requiresManualSermonReview());
    }

    #[Test]
    public function manual_review_is_not_required_for_audio_type_even_if_failed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                ],
            ],
        ]);

        $this->assertFalse($log->requiresManualSermonReview());
    }

    #[Test]
    public function it_retrieves_manually_confirmed_segment_id(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_metadata' => [
                'manual_review' => [
                    'confirmed_segment_id' => 123,
                ],
            ],
        ]);

        $this->assertEquals(123, $log->manuallyConfirmedSegmentId());
    }

    #[Test]
    public function it_determines_video_processing_mode(): void
    {
        $log = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'auto_trim'],
        ]);
        $this->assertEquals('auto_trim', $log->videoProcessingMode());

        $log2 = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['trim_requested' => true],
        ]);
        $this->assertEquals('auto_trim', $log2->videoProcessingMode());

        $log3 = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['trim_requested' => false],
        ]);
        $this->assertEquals('full_video', $log3->videoProcessingMode());

        $log4 = MediaProcessingLog::factory()->audio()->create();
        $this->assertEquals('full_video', $log4->videoProcessingMode());
    }

    #[Test]
    public function it_identifies_auto_trim_runs(): void
    {
        $log = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'auto_trim'],
        ]);
        $this->assertTrue($log->isAutoTrimVideoRun());

        $log2 = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'full_video'],
        ]);
        $this->assertFalse($log2->isAutoTrimVideoRun());
    }

    #[Test]
    public function it_determines_if_segmentation_pipeline_is_used(): void
    {
        $livestreamLog = MediaProcessingLog::factory()->livestream()->create();
        $this->assertTrue($livestreamLog->usesSegmentationPipeline());

        $autoTrimLog = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'auto_trim'],
        ]);
        $this->assertTrue($autoTrimLog->usesSegmentationPipeline());

        $fullVideoLog = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'full_video'],
        ]);
        $this->assertFalse($fullVideoLog->usesSegmentationPipeline());
    }

    #[Test]
    public function it_correctly_manages_video_quality_metadata(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $quality = ['bitrate' => 5000, 'resolution' => '1080p'];

        $log->putVideoQualityMetadata($quality);

        $this->assertEquals($quality, $log->fresh()->videoQualityMetadata());
    }

    #[Test]
    public function it_identifies_all_temporary_file_paths(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'temp/source.mp4',
            'enhanced_audio_file_path' => 'temp/enhanced.mp3',
            'rms_log_path' => 'temp/rms.log',
            'video_file_path' => 'temp/preview.mp4',
            'processing_metadata' => [
                'extracted_segment_path' => 'temp/segment.mp4',
                'extracted_audio_path' => 'temp/audio.mp3',
                'temp_video_path' => 'temp/video.mp4',
            ],
        ]);

        $paths = $log->temporaryFilePaths();

        $this->assertContains('temp/source.mp4', $paths);
        $this->assertContains('temp/enhanced.mp3', $paths);
        $this->assertNotContains('temp/rms.log', $paths);
        $this->assertContains('temp/preview.mp4', $paths);
        $this->assertContains('temp/segment.mp4', $paths);
        $this->assertContains('temp/audio.mp3', $paths);
        $this->assertContains('temp/video.mp4', $paths);
    }

    #[Test]
    public function it_identifies_pipeline_profile_names(): void
    {
        $audio = MediaProcessingLog::factory()->audio()->create();
        $this->assertEquals('audio', $audio->processingPipelineProfile());

        $livestream = MediaProcessingLog::factory()->livestream()->create();
        $this->assertEquals('livestream', $livestream->processingPipelineProfile());

        $autoTrim = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'auto_trim'],
        ]);
        $this->assertEquals('video_auto_trim', $autoTrim->processingPipelineProfile());

        $fullVideo = MediaProcessingLog::factory()->video()->create([
            'processing_metadata' => ['video_processing_mode' => 'full_video'],
        ]);
        $this->assertEquals('video', $fullVideo->processingPipelineProfile());
    }

    #[Test]
    public function it_returns_static_validation_rules(): void
    {
        $rules = MediaProcessingLog::validationRules();

        $this->assertIsArray($rules);
        $this->assertArrayHasKey('processing_id', $rules);
        $this->assertArrayHasKey('processing_type', $rules);
        $this->assertArrayHasKey('status', $rules);
    }
}

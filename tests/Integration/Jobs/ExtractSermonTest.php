<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\ExtractSermon;
use App\Mail\ManualReviewRequired;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractSermonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $job = new ExtractSermon($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3600, $job->timeout);
    }

    #[Test]
    public function it_skips_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['status' => 'cancelled']);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);
    }

    #[Test]
    public function it_throws_when_sermon_times_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => null,
            'sermon_end_time' => null,
            'source_file_path' => 'livestreams/test.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon segment times not found');

        $this->runJob($job, $mockExtractor, $mockStorage);
    }

    #[Test]
    public function it_updates_processing_log_with_extraction_data(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        // Create a temporary video file
        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/test-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        // Create a temporary extracted audio file for verification
        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/sermon-audio.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/test-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('extracted/sermon-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/sermon-audio.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertEquals('extraction_complete', $log->current_step);
        $this->assertEquals('extracted/sermon-video.mp4', $log->video_file_path);
        $this->assertEquals('extracted/sermon-audio.mp3', $log->audio_file_path);
        $this->assertNotNull($log->processing_metadata);
        $this->assertArrayHasKey('audio_compression', $log->processing_metadata);
        $this->assertTrue($log->processing_metadata['audio_compression']['compression_applied']);

        // The resolved plan is recorded so the cut's provenance survives log rotation.
        $plan = $log->processing_metadata['sermon_extraction_plan'] ?? null;
        $this->assertIsArray($plan);
        $this->assertSame('processing_log', $plan['source']);
        $this->assertSame('baseline', $plan['mode']);
        $this->assertEqualsWithDelta(300.0, $plan['segments'][0]['start_time'], 0.01);
        $this->assertEqualsWithDelta(2100.0, $plan['segments'][0]['end_time'], 0.01);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $log->processing_id,
            'step' => ChurchServiceProcessingTimeline::EXTRACT_SERMON,
            'status' => 'completed',
        ]);

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_prefers_high_confidence_classified_sermon_section_when_config_enabled(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => true]);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/classified-preferred.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/classified-preferred.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            // No baseline sermon times; should use section bounds
            'sermon_start_time' => null,
            'sermon_end_time' => null,
            'source_file_path' => 'livestreams/classified-preferred.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'start_time' => 420.0,
            'end_time' => 1980.0,
            'duration' => 1560.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with(
                $this->anything(),
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 420.0
                        && $segment->endTime === 1980.0;
                }),
                $this->anything()
            )
            ->willReturn('extracted/classified-preferred-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/classified-preferred.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        $this->assertSame('extracted/classified-preferred-video.mp4', $log->video_file_path);

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_falls_back_to_processing_log_times_when_classified_section_is_not_high_confidence(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => true]);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/classified-fallback.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/classified-fallback.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/classified-fallback.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'start_time' => 900.0,
            'end_time' => 1800.0,
            'duration' => 900.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'openlp_aligned',
                'review_reason' => 'expected_type_mismatch',
            ],
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with(
                $this->anything(),
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 300.0
                        && $segment->endTime === 2100.0;
                }),
                $this->anything()
            )
            ->willReturn('extracted/classified-fallback-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/classified-fallback.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        $this->assertSame('extracted/classified-fallback-video.mp4', $log->video_file_path);

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_uses_processing_log_times_when_section_preference_is_disabled(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => false]);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/classified-disabled.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/classified-disabled.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 200.0,
            'sermon_end_time' => 2200.0,
            'source_file_path' => 'livestreams/classified-disabled.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'start_time' => 600.0,
            'end_time' => 1800.0,
            'duration' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with(
                $this->anything(),
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 200.0
                        && $segment->endTime === 2200.0;
                }),
                $this->anything()
            )
            ->willReturn('extracted/classified-disabled-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/classified-disabled.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        $this->assertSame('extracted/classified-disabled-video.mp4', $log->video_file_path);

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_uses_concat_extraction_plan_for_non_adjacent_bible_and_sermon_sections(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => true]);
        config(['media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat' => true]);
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/classified-concat.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/classified-concat.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $concatVideo = storage_path('app/temp/concat-sermon.mp4');
        if (! is_dir(dirname($concatVideo))) {
            mkdir(dirname($concatVideo), 0755, true);
        }
        file_put_contents($concatVideo, str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => null,
            'sermon_end_time' => null,
            'source_file_path' => 'livestreams/classified-concat.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'bible_reading',
            'section_order' => 1,
            'start_time' => 120.0,
            'end_time' => 300.0,
            'duration' => 180.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'section_order' => 2,
            'start_time' => 900.0,
            'end_time' => 2100.0,
            'duration' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
            ],
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractConcatenatedSegmentAsFile')
            ->willReturn('temp/concat-sermon.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->with(
                $concatVideo,
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 0.0
                        && $segment->endTime === 1380.0;
                }),
                $this->anything()
            )
            ->willReturn([
                'audio_path' => 'extracted/classified-concat.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        $this->assertSame('temp/concat-sermon.mp4', $log->video_file_path);

        // The concat cut joins a 180s reading and a 1200s sermon (1380s of
        // media) across a gap. The run bounds must describe that duration, not
        // the 1980s continuous span from first-start to last-end — downstream
        // (SubmitToProcessing, SermonMetadataIntegration, StandardProcessingResponse)
        // read sermon_end_time - sermon_start_time as the segment duration.
        $this->assertEqualsWithDelta(120.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(1500.0, (float) $log->sermon_end_time, 0.01);
        $this->assertEqualsWithDelta(
            1380.0,
            (float) $log->sermon_end_time - (float) $log->sermon_start_time,
            0.01
        );

        @unlink($videoFile);
        @unlink($extractedAudioFile);
        @unlink($concatVideo);
    }

    #[Test]
    public function it_uses_clear_dominant_speech_segment_when_extracting_from_processing_log(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/dominant-speech.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/dominant-speech.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 999.0,
            'source_file_path' => 'livestreams/dominant-speech.mp4',
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 300.0,
            'end_time' => 1800.0,
            'duration' => 1500.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 1850.0,
            'end_time' => 2600.0,
            'duration' => 750.0,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with(
                $this->anything(),
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 300.0
                        && $segment->endTime === 1800.0;
                }),
                $this->anything()
            )
            ->willReturn('extracted/dominant-speech-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/dominant-speech.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        Mail::assertNothingQueued();

        // The guard replaced the baseline plan with the dominant RMS segment,
        // so the run fields must follow it — the Sermon record built from them
        // has to describe the media actually cut, not the stale baseline.
        $this->assertEqualsWithDelta(300.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(1800.0, (float) $log->sermon_end_time, 0.01);
        $this->assertSame(
            'dominant_speech_segment',
            $log->processing_metadata['sermon_extraction_plan']['strategy'] ?? null
        );

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_stores_structured_manual_review_metadata_when_flagging_ambiguous_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/review-structured.mp4',
        ]);

        // Two similarly-sized speech blocks — ratio will fail
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1320.0,
            'duration' => 1320.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 1400.0,
            'end_time' => 2300.0,
            'duration' => 900.0,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');
        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $review = $log->manualReviewMetadata();
        $this->assertSame('required', $review['status']);
        $this->assertSame('ratio_below_threshold', $review['reason_code']);
        $this->assertNotEmpty($review['reason_message']);
        $this->assertNotNull($review['flagged_at']);
        $this->assertNotEmpty($review['speech_segments']);
    }

    #[Test]
    public function it_bypasses_confidence_gating_and_extracts_when_segment_is_manually_confirmed(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/confirmed-segment.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/confirmed-segment.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 999.0,
            'source_file_path' => 'livestreams/confirmed-segment.mp4',
        ]);

        // Two similar-sized blocks that would normally fail the confidence check
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 600.0,
            'end_time' => 1800.0,
            'duration' => 1200.0,
        ]);

        $confirmedSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 2000.0,
            'end_time' => 3100.0,
            'duration' => 1100.0,
        ]);

        // Simulate the admin having already confirmed the second segment
        $log->update([
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'confirmed',
                    'reason_code' => 'ratio_below_threshold',
                    'reason_message' => 'The longest speech block was not at least 1.5x longer.',
                    'flagged_at' => now()->subMinutes(10)->toIso8601String(),
                    'speech_segments' => [],
                    'confirmed_segment_id' => $confirmedSegment->id,
                    'confirmed_by_user_id' => 1,
                    'confirmed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with(
                $this->anything(),
                $this->callback(function ($segment): bool {
                    return $segment instanceof \App\Data\LivestreamSegment
                        && $segment->startTime === 2000.0
                        && $segment->endTime === 3100.0;
                }),
                $this->anything()
            )
            ->willReturn('extracted/confirmed-segment-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/confirmed-segment.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('extraction_complete', $log->current_step);
        $this->assertSame('extracted/confirmed-segment-video.mp4', $log->video_file_path);
        Mail::assertNothingQueued();

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_marks_for_manual_review_and_stops_when_multiple_long_speech_blocks_are_similar(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/review-multiple.mp4',
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1500.0,
            'duration' => 1500.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 1600.0,
            'end_time' => 2900.0,
            'duration' => 1300.0,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');
        $mockExtractor->expects($this->never())->method('extractOptimizedAudio');

        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertStringContainsString('Multiple speech blocks met the 20-minute sermon threshold.', $log->error_message ?? '');
        Mail::assertQueued(ManualReviewRequired::class);
    }

    #[Test]
    public function it_marks_for_manual_review_when_no_speech_block_meets_twenty_minutes(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/review-none.mp4',
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 600.0,
            'duration' => 600.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 650.0,
            'end_time' => 1250.0,
            'duration' => 600.0,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');
        $mockExtractor->expects($this->never())->method('extractOptimizedAudio');

        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertStringContainsString('No speech block met the 20-minute sermon threshold.', $log->error_message ?? '');
        Mail::assertQueued(ManualReviewRequired::class);
    }

    #[Test]
    public function it_marks_for_manual_review_when_ratio_threshold_is_not_met(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/review-ratio.mp4',
        ]);

        // 22 minutes - only one exceeds 20 min threshold
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1320.0,
            'duration' => 1320.0,
        ]);

        // 15 minutes - 1320 < 900 * 1.5 = 1350, so ratio fails
        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 2,
            'segment_order' => 2,
            'start_time' => 1400.0,
            'end_time' => 2300.0,
            'duration' => 900.0,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');
        $mockExtractor->expects($this->never())->method('extractOptimizedAudio');

        $mockStorage = $this->createStub(VideoStorageService::class);

        Mail::fake();
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $this->runJob($job, $mockExtractor, $mockStorage);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertStringContainsString('not at least 1.5x longer', $log->error_message ?? '');
        Mail::assertQueued(ManualReviewRequired::class);
    }

    #[Test]
    public function it_marks_as_failed_when_extraction_throws(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/fail-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/fail-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willThrowException(new \Exception('FFmpeg segfault'));

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        try {
            $this->runJob($job, $mockExtractor, $mockStorage);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('FFmpeg segfault', $e->getMessage());
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Sermon extraction failed', $log->error_message);

        @unlink($videoFile);
    }

    #[Test]
    public function it_throws_when_audio_file_does_not_exist_after_extraction(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/verify-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/verify-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('extracted/sermon-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/ghost-audio.mp3',
                'full_path' => '/nonexistent/path/ghost-audio.mp3',
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createStub(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        try {
            $this->runJob($job, $mockExtractor, $mockStorage);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('file does not exist', $e->getMessage());
        }

        @unlink($videoFile);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);
        $job->failed(new \Exception('Extraction timeout'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Sermon extraction failed after', $log->error_message);
    }

    private function runJob(
        ExtractSermon $job,
        VideoExtractionService $mockExtractor,
        VideoStorageService $mockStorage
    ): void {
        $job->handle(
            $mockExtractor,
            $mockStorage,
            app(StorageAdapterHelper::class),
            app(SermonExtractionPlanResolver::class),
            app(SermonCandidateConfidenceService::class)
        );
    }
}

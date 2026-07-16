<?php

declare(strict_types=1);

namespace Tests\Integration\Data;

use App\Data\StandardProcessingResponse;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonStorageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StandardProcessingResponseTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    #[Test]
    public function found_sets_found_true_and_captures_all_fields(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        $updatedAt = Carbon::parse('2026-01-15 10:05:00');

        $response = StandardProcessingResponse::found(
            processingId: 'test-123',
            status: 'processing',
            currentStep: 'transcription',
            progressPercentage: 70,
            errorMessage: null,
            sermonId: 42,
            sermonUrl: '/christ/sermons/test-sermon',
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            estimatedCompletion: '2026-01-15T10:10:00Z',
            additionalData: ['custom_key' => 'custom_value'],
        );

        $this->assertTrue($response->found);
        $this->assertEquals('test-123', $response->processingId);
        $this->assertEquals('processing', $response->status);
        $this->assertEquals('transcription', $response->currentStep);
        $this->assertEquals(70, $response->progressPercentage);
        $this->assertNull($response->errorMessage);
        $this->assertEquals(42, $response->sermonId);
        $this->assertEquals('/christ/sermons/test-sermon', $response->sermonUrl);
        $this->assertEquals($startedAt, $response->startedAt);
        $this->assertEquals($updatedAt, $response->updatedAt);
        $this->assertEquals('2026-01-15T10:10:00Z', $response->estimatedCompletion);
        $this->assertEquals(['custom_key' => 'custom_value'], $response->additionalData);
    }

    #[Test]
    public function not_found_sets_found_false(): void
    {
        $response = StandardProcessingResponse::notFound();

        $this->assertFalse($response->found);
        $this->assertNull($response->processingId);
        $this->assertNull($response->status);
        $this->assertEquals(0, $response->progressPercentage);
    }

    #[Test]
    public function error_sets_found_false_and_message(): void
    {
        $response = StandardProcessingResponse::error('Something went wrong');

        $this->assertFalse($response->found);
        $this->assertEquals('Something went wrong', $response->errorMessage);
    }

    #[Test]
    public function diagnostics_are_serialized_as_additional_data(): void
    {
        $response = StandardProcessingResponse::found(
            processingId: 'test-456',
            status: 'processing',
            currentStep: 'extraction',
            progressPercentage: 50,
            additionalData: [
                'processing_steps' => [['step' => 'extraction', 'status' => 'processing']],
                'processing_metadata' => ['trim_requested' => true],
                'queue_name' => 'media-processing',
            ],
        );

        $this->assertTrue($response->found);
        $this->assertSame('extraction', $response->additionalData['processing_steps'][0]['step']);
        $this->assertTrue($response->additionalData['processing_metadata']['trim_requested']);
        $this->assertSame('media-processing', $response->additionalData['queue_name']);
    }

    #[Test]
    public function from_processing_log_does_not_leak_internal_video_file_path(): void
    {
        $log = MediaProcessingLog::factory()->video()->completed()->create([
            'video_file_path' => 'sermons/2026/03/video.mp4',
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertArrayNotHasKey('video_file_path', $response->additionalData);
    }

    #[Test]
    public function from_processing_status_delegates_to_found(): void
    {
        $response = StandardProcessingResponse::fromProcessingStatus(
            processingId: 'test-789',
            status: 'completed',
            progressPercentage: 100,
        );

        $this->assertTrue($response->found);
        $this->assertEquals('test-789', $response->processingId);
        $this->assertEquals('completed', $response->status);
        $this->assertEquals(100, $response->progressPercentage);
    }

    #[Test]
    public function from_processing_log_uses_public_thumbnail_url_from_storage_service(): void
    {
        Storage::fake('do_spaces');
        Config::set('thumbnail-generation.storage.disk', 'do_spaces');
        Config::set('filesystems.disks.do_spaces.cdn_endpoint', 'https://cdn.example.com');
        app(SermonStorageService::class)->clearInternalCaches();

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        $log = MediaProcessingLog::factory()
            ->video()
            ->withSermon($sermon)
            ->create([
                'video_file_path' => 'sermons/2026/03/video.mp4',
            ]);

        $response = StandardProcessingResponse::fromProcessingLog($log->fresh('sermon'));

        $this->assertTrue($response->additionalData['thumbnail_generated']);
        $this->assertStringStartsWith(
            'https://cdn.example.com/sermons/thumbnails/test-thumbnail.jpg?v=',
            (string) $response->additionalData['thumbnail_url'],
        );
    }

    // -------------------------------------------------------------------------
    // Status check helpers
    // -------------------------------------------------------------------------

    #[Test]
    public function is_complete_returns_true_only_for_found_completed(): void
    {
        $this->assertTrue(StandardProcessingResponse::found('id', 'completed')->isComplete());
        $this->assertFalse(StandardProcessingResponse::found('id', 'processing')->isComplete());
        $this->assertFalse(StandardProcessingResponse::found('id', 'failed')->isComplete());
        $this->assertFalse(StandardProcessingResponse::notFound()->isComplete());
    }

    #[Test]
    public function is_failed_returns_true_only_for_found_failed(): void
    {
        $this->assertTrue(StandardProcessingResponse::found('id', 'failed')->isFailed());
        $this->assertFalse(StandardProcessingResponse::found('id', 'completed')->isFailed());
        $this->assertFalse(StandardProcessingResponse::notFound()->isFailed());
    }

    #[Test]
    public function is_cancelled_returns_true_only_for_found_cancelled(): void
    {
        $this->assertTrue(StandardProcessingResponse::found('id', 'cancelled')->isCancelled());
        $this->assertFalse(StandardProcessingResponse::found('id', 'processing')->isCancelled());
        $this->assertFalse(StandardProcessingResponse::notFound()->isCancelled());
    }

    #[Test]
    public function is_in_progress_returns_true_for_pending_and_processing(): void
    {
        $this->assertTrue(StandardProcessingResponse::found('id', 'pending')->isInProgress());
        $this->assertTrue(StandardProcessingResponse::found('id', 'processing')->isInProgress());
        $this->assertFalse(StandardProcessingResponse::found('id', 'completed')->isInProgress());
        $this->assertFalse(StandardProcessingResponse::found('id', 'failed')->isInProgress());
        $this->assertFalse(StandardProcessingResponse::notFound()->isInProgress());
    }

    // -------------------------------------------------------------------------
    // toArray serialisation
    // -------------------------------------------------------------------------

    #[Test]
    public function to_array_for_not_found_returns_minimal_structure(): void
    {
        $array = StandardProcessingResponse::notFound()->toArray();

        $this->assertFalse($array['found']);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayNotHasKey('processing_id', $array);
        $this->assertArrayNotHasKey('status', $array);
    }

    #[Test]
    public function to_array_for_error_includes_error_message_in_message_key(): void
    {
        $array = StandardProcessingResponse::error('Custom error')->toArray();

        $this->assertFalse($array['found']);
        $this->assertEquals('Custom error', $array['message']);
    }

    #[Test]
    public function to_array_for_found_includes_all_required_fields(): void
    {
        $array = StandardProcessingResponse::found(
            processingId: 'proc-001',
            status: 'processing',
            currentStep: 'transcription',
            progressPercentage: 70,
        )->toArray();

        $this->assertTrue($array['found']);
        $this->assertEquals('proc-001', $array['processing_id']);
        $this->assertEquals('processing', $array['status']);
        $this->assertEquals('transcription', $array['current_step']);
        $this->assertEquals(70, $array['progress_percentage']);
        $this->assertArrayHasKey('started_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
        $this->assertArrayHasKey('created_at', $array); // backwards compatibility alias
    }

    #[Test]
    public function to_array_omits_optional_fields_when_null(): void
    {
        $array = StandardProcessingResponse::found('proc-002', 'processing')->toArray();

        $this->assertArrayNotHasKey('error_message', $array);
        $this->assertArrayNotHasKey('sermon_id', $array);
        $this->assertArrayNotHasKey('sermon_url', $array);
        $this->assertArrayNotHasKey('estimated_completion', $array);
        $this->assertArrayNotHasKey('processing_steps', $array);
        $this->assertArrayNotHasKey('processing_metadata', $array);
    }

    #[Test]
    public function to_array_includes_optional_fields_when_set(): void
    {
        $array = StandardProcessingResponse::found(
            processingId: 'proc-003',
            status: 'failed',
            errorMessage: 'Transcription timed out',
            sermonId: 7,
            sermonUrl: '/christ/sermons/sunday',
            estimatedCompletion: '2026-01-15T10:10:00Z',
            additionalData: ['segments_count' => 3],
        )->toArray();

        $this->assertEquals('Transcription timed out', $array['error_message']);
        $this->assertEquals(7, $array['sermon_id']);
        $this->assertEquals('/christ/sermons/sunday', $array['sermon_url']);
        $this->assertEquals('2026-01-15T10:10:00Z', $array['estimated_completion']);
        $this->assertEquals(3, $array['segments_count']); // merged from additionalData
    }

    #[Test]
    public function to_array_merges_additional_data_into_root(): void
    {
        $array = StandardProcessingResponse::found(
            processingId: 'proc-004',
            status: 'processing',
            additionalData: ['video_file_path' => 'sermons/1/video.mp4', 'has_thumbnail' => true],
        )->toArray();

        $this->assertEquals('sermons/1/video.mp4', $array['video_file_path']);
        $this->assertTrue($array['has_thumbnail']);
    }

    #[Test]
    public function to_array_formats_timestamps_as_iso_strings(): void
    {
        $started = Carbon::parse('2026-01-15 09:00:00', 'UTC');
        $updated = Carbon::parse('2026-01-15 09:30:00', 'UTC');

        $array = StandardProcessingResponse::found(
            processingId: 'proc-005',
            status: 'processing',
            startedAt: $started,
            updatedAt: $updated,
        )->toArray();

        $this->assertEquals($started->toISOString(), $array['started_at']);
        $this->assertEquals($updated->toISOString(), $array['updated_at']);
        $this->assertEquals($started->toISOString(), $array['created_at']); // backwards compat
    }

    // -------------------------------------------------------------------------
    // fromProcessingLog — progress mapping
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function stepProgressProvider(): array
    {
        return [
            // null/unknown step hits the default branch
            'unknown_step falls to default' => ['unknown_step', 50],
            'audio_processing_initiated' => ['audio_processing_initiated', 10],
            'video_processing_initiated' => ['video_processing_initiated', 10],
            'visual_analysis' => ['visual_analysis', 10],
            'validating' => ['validating', 15],
            'rms_generation' => ['rms_generation', 20],
            'extracting_audio' => ['extracting_audio', 25],
            'segmentation' => ['segmentation', 30],
            'analyzing_segments' => ['analyzing_segments', 40],
            'extraction' => ['extraction', 57],
            'extracting_sermon' => ['extracting_sermon', 57],
            'sermon_creation' => ['sermon_creation', 60],
            'creating_sermon' => ['creating_sermon', 60],
            'transcribing_audio' => ['transcribing_audio', 70],
            'transcription_completed' => ['transcription_completed', 70],
            'analyzing_transcript' => ['analyzing_transcript', 85],
            'ai_analysis_completed' => ['ai_analysis_completed', 85],
            'generating_thumbnail' => ['generating_thumbnail', 89],
            'cleanup' => ['cleanup', 95],
        ];
    }

    #[Test]
    #[DataProvider('stepProgressProvider')]
    public function from_processing_log_maps_step_to_correct_progress(string $step, int $expectedProgress): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => $step,
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertEquals($expectedProgress, $response->progressPercentage);
    }

    #[Test]
    public function from_processing_log_uses_media_specific_progress_for_shared_steps(): void
    {
        $thumbnailLog = MediaProcessingLog::factory()->video()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'generating_thumbnail',
        ]);

        $this->assertEquals(89, StandardProcessingResponse::fromProcessingLog($thumbnailLog)->progressPercentage);
    }

    #[Test]
    public function from_processing_log_returns_100_when_completed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertEquals(100, $response->progressPercentage);
        $this->assertTrue($response->isComplete());
    }

    #[Test]
    public function from_processing_log_returns_0_when_failed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertEquals(0, $response->progressPercentage);
        $this->assertTrue($response->isFailed());
    }

    #[Test]
    public function from_processing_log_returns_0_when_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create();

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertEquals(0, $response->progressPercentage);
        $this->assertTrue($response->isCancelled());
    }

    #[Test]
    public function from_processing_log_includes_sermon_url_when_sermon_attached(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();
        $sermon = $log->fresh('sermon')->sermon;

        $response = StandardProcessingResponse::fromProcessingLog($log->fresh('sermon'));

        $this->assertStringContainsString($sermon->slug, $response->sermonUrl);
        $this->assertEquals($sermon->id, $response->sermonId);
    }

    #[Test]
    public function from_processing_log_audio_type_includes_audio_duration_metadata(): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
            'duration' => 1800.0,
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertArrayHasKey('audio_duration', $response->additionalData);
        $this->assertEquals(1800.0, $response->additionalData['audio_duration']);
    }

    #[Test]
    public function from_processing_log_video_type_includes_video_duration_metadata(): void
    {
        $log = MediaProcessingLog::factory()->video()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
            'duration' => 3600.0,
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertArrayHasKey('video_duration', $response->additionalData);
        $this->assertEquals(3600.0, $response->additionalData['video_duration']);
    }

    #[Test]
    public function from_processing_log_livestream_type_includes_segments_count_metadata(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'segmentation',
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertArrayHasKey('segments_count', $response->additionalData);
        $this->assertEquals(0, $response->additionalData['segments_count']);
    }

    #[Test]
    public function from_processing_log_sets_processing_id_and_status_from_model(): void
    {
        $log = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
            'error_message' => null,
        ]);

        $response = StandardProcessingResponse::fromProcessingLog($log);

        $this->assertTrue($response->found);
        $this->assertEquals($log->processing_id, $response->processingId);
        $this->assertEquals('processing', $response->status);
        $this->assertEquals('transcribing_audio', $response->currentStep);
    }
}

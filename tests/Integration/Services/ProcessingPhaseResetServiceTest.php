<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\ProcessingPhaseResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingPhaseResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingPhaseResetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProcessingPhaseResetService::class);
    }

    // ── reset_scope: none ─────────────────────────────────────────────────────

    #[Test]
    public function it_does_nothing_when_reset_scope_is_none(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'rms_stats' => ['peak' => 0.9],
        ]);

        $this->service->resetForRetry($log, ['action' => 'dispatch_chain', 'reset_scope' => 'none']);

        $log->refresh();
        $this->assertNotNull($log->rms_stats);
    }

    #[Test]
    public function it_does_nothing_when_reset_scope_is_absent(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'rms_stats' => ['peak' => 0.9],
        ]);

        $this->service->resetForRetry($log, ['action' => 'dispatch_chain']);

        $log->refresh();
        $this->assertNotNull($log->rms_stats);
    }

    // ── reset_scope: analyze_segments ────────────────────────────────────────

    #[Test]
    public function it_deletes_segments_when_scope_is_analyze_segments(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create();

        LivestreamSegment::factory()->forProcessingLog($log->id)->create();
        LivestreamSegment::factory()->forProcessingLog($log->id)->create();

        $this->assertSame(2, $log->segments()->count());

        $this->service->resetForRetry($log, [
            'action' => 'dispatch_livestream_chain',
            'reset_scope' => 'analyze_segments',
        ]);

        $this->assertSame(0, $log->segments()->count());
    }

    #[Test]
    public function it_clears_segmentation_fields_when_scope_is_analyze_segments(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'sermon_start_time' => 120.5,
            'sermon_end_time' => 3720.0,
            'threshold_method' => 'adaptive',
            'adaptive_threshold' => 0.05,
            'rms_stats' => ['peak' => 0.85],
        ]);

        $this->service->resetForRetry($log, [
            'action' => 'dispatch_livestream_chain',
            'reset_scope' => 'analyze_segments',
        ]);

        $log->refresh();
        $this->assertNull($log->sermon_start_time);
        $this->assertNull($log->sermon_end_time);
        $this->assertNull($log->threshold_method);
        $this->assertNull($log->adaptive_threshold);
        $this->assertNull($log->rms_stats);
    }

    #[Test]
    public function it_clears_only_the_active_review_marker_for_a_structure_validation_retry(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'llm_structure_validation_failed',
                ],
                'service_transcript_path' => 'historic/transcript.json',
                'service_structure_proposal' => ['passed_validation' => false],
            ],
        ]);

        $this->service->resetForRetry($log, [
            'action' => 'dispatch_livestream_chain',
            'reset_scope' => 'service_structure_validation',
        ]);

        $metadata = $log->fresh()?->processing_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('manual_review', $metadata);
        $this->assertSame('historic/transcript.json', $metadata['service_transcript_path']);
        $this->assertArrayHasKey('service_structure_proposal', $metadata);
    }

    // ── reset_scope: submit_to_processing ─────────────────────────────────────

    #[Test]
    public function it_removes_final_video_path_from_metadata_when_scope_is_submit_to_processing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_metadata' => [
                'final_video_path' => 'sermons/video/final.mp4',
                'other_key' => 'should-remain',
            ],
        ]);

        $this->service->resetForRetry($log, [
            'action' => 'dispatch_livestream_chain',
            'reset_scope' => 'submit_to_processing',
        ]);

        $log->refresh();
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('final_video_path', $metadata);
        $this->assertArrayHasKey('other_key', $metadata);
    }

    #[Test]
    public function it_associates_an_existing_sermon_by_livestream_processing_id(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_id' => 'test-run-abc',
            'sermon_id' => null,
            'processing_metadata' => [],
        ]);

        $sermon->update(['livestream_processing_id' => 'test-run-abc']);

        $this->service->resetForRetry($log, [
            'action' => 'dispatch_livestream_chain',
            'reset_scope' => 'submit_to_processing',
        ]);

        $log->refresh();
        $this->assertSame($sermon->id, $log->sermon_id);
    }
}

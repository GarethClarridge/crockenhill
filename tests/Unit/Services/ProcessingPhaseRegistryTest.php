<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingPhaseRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingPhaseRegistryTest extends TestCase
{
    #[Test]
    public function it_exposes_audio_phases_in_pipeline_order(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $phases = $registry->phasesForPipeline('audio');

        $this->assertSame([
            'initiated_from_livestream',
            'audio_initiated',
            'validate_audio',
            'create_sermon_record',
            'transcribe_audio',
            'analyze_transcript',
            'update_sermon_record',
            'send_notification',
            'notification_complete',
            'cleanup',
        ], array_column($phases, 'key'));
    }

    #[Test]
    public function it_maps_legacy_and_manual_review_steps_to_registry_progress(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $this->assertSame(56, $registry->progressForStep('manual_review_confirmed'));
        $this->assertSame(10, $registry->progressForStep('initiated_from_livestream:abc123'));
        $this->assertSame(10, $registry->progressForStep('restarting_from_beginning'));
        $this->assertSame(87, $registry->progressForStep('updating_sermon_record'));
        $this->assertSame(92, $registry->progressForStep('sending_notification'));
        $this->assertSame(93, $registry->progressForStep('notification_sent'));
    }

    #[Test]
    public function it_prefers_media_specific_progress_mappings_for_shared_step_names(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $this->assertSame(87, $registry->progressForStep('updating_sermon_record', MediaType::Audio));
        $this->assertSame(90, $registry->progressForStep('updating_sermon_record', MediaType::Video));
        $this->assertSame(88, $registry->progressForStep('generating_thumbnail', MediaType::Video));
        $this->assertSame(90, $registry->progressForStep('generating_thumbnail', MediaType::Livestream));
    }

    #[Test]
    public function it_retries_livestream_runs_from_the_active_phase_cursor(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
        ]);

        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 12,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
    }

    #[Test]
    public function it_maps_section_publication_preparation_to_a_retryable_livestream_phase(): void
    {
        $registry = app(ProcessingPhaseRegistry::class);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
            'current_step' => 'preparing_section_publication_candidates',
        ]);

        $this->assertSame(91, $registry->progressForStep('preparing_section_publication_candidates', MediaType::Livestream));
        $this->assertSame([
            'action' => 'dispatch_livestream_chain',
            'pipeline' => 'livestream',
            'job_offset' => 15,
            'rerun_strategy' => 'safe_to_rerun',
            'reset_scope' => 'none',
        ], $registry->retryPlanFor($processingLog));
    }
}

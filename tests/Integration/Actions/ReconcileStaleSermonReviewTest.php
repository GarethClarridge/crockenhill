<?php

declare(strict_types=1);

namespace Tests\Integration\Actions;

use App\Actions\ReconcileStaleSermonReview;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\ExtractSermon;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileStaleSermonReviewTest extends TestCase
{
    use RefreshDatabase;

    private ReconcileStaleSermonReview $action;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->action = app(ReconcileStaleSermonReview::class);
    }

    #[Test]
    public function it_resumes_auto_extraction_when_a_sermon_section_exists_and_source_survives(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/service.mp4', 'fake-video');

        $log = $this->logAwaitingReview('livestreams/service.mp4');
        $this->sermonSection($log);

        $outcome = $this->action->execute($log, execute: true);

        $this->assertSame('resumed', $outcome);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Pending, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        // The whole point: no human segment was picked, so extraction falls
        // through to the detected sermon section's boundaries.
        $this->assertNull($log->manuallyConfirmedSegmentId());
        Queue::assertPushed(ExtractSermon::class);
    }

    #[Test]
    public function it_clears_the_phantom_when_the_source_recording_is_gone(): void
    {
        Queue::fake();

        $log = $this->logAwaitingReview('livestreams/missing.mp4');
        $this->sermonSection($log);

        $outcome = $this->action->execute($log, execute: true);

        $this->assertSame('cleared', $outcome);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Cancelled, $log->status);
        $this->assertFalse($log->requiresManualSermonReview());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_skips_a_run_that_still_needs_a_genuine_manual_selection(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/service.mp4', 'fake-video');

        $log = $this->logAwaitingReview('livestreams/service.mp4');
        // No sermon section detected — the reviewer must still pick.

        $outcome = $this->action->execute($log, execute: true);

        $this->assertSame('skipped', $outcome);
        $this->assertTrue($log->fresh()->requiresManualSermonReview());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        Queue::fake();
        Storage::disk('local')->put('livestreams/service.mp4', 'fake-video');

        $log = $this->logAwaitingReview('livestreams/service.mp4');
        $this->sermonSection($log);

        $outcome = $this->action->execute($log, execute: false);

        $this->assertSame('resumed', $outcome);
        $this->assertTrue($log->fresh()->requiresManualSermonReview());
        Queue::assertNothingPushed();
    }

    private function logAwaitingReview(string $sourcePath): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourcePath,
        ]);

        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            reasonCode: 'no_qualifying_speech_block',
            reasonMessage: 'No speech block met the 20-minute sermon threshold.',
            speechSegments: [],
        );

        return $log->fresh();
    }

    private function sermonSection(MediaProcessingLog $log): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'status' => ServiceSectionStatus::Identified,
            'confidence' => 0.9,
            'start_time' => 1800.0,
            'end_time' => 3600.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
    }
}

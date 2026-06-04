<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Jobs\ExtractSermon;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ProcessVideoCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resumes_the_canonical_livestream_flow_from_manual_review(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $processingId = 'test-id';
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => $processingId,
            'source_file_path' => 'livestreams/source.mp4',
        ]);

        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            reasonCode: 'multiple_qualifying_speech_blocks',
            reasonMessage: 'Multiple speech blocks qualified.',
            speechSegments: [],
        );
        Storage::disk('local')->put('livestreams/source.mp4', 'content');

        $segment = LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'classification' => 'speech',
            'duration' => 1200,
            'start_time' => 100,
            'end_time' => 1300,
        ]);

        $this->artisan('livestream:create-sermon', ['processing_id' => $processingId])
            ->assertExitCode(0)
            ->expectsOutputToContain('Resumed the canonical livestream sermon flow.');

        $log->refresh();
        $this->assertSame(ProcessingStatus::Pending, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertSame($segment->id, $log->manuallyConfirmedSegmentId());
        Queue::assertPushed(ExtractSermon::class);
        $this->assertDatabaseCount('sermons', 0);
    }

    #[Test]
    public function it_fails_if_processing_log_not_found(): void
    {
        $this->artisan('livestream:create-sermon', ['processing_id' => 'non-existent'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Processing log not found');
    }

    #[Test]
    public function it_fails_if_no_speech_segment_found(): void
    {
        $processingId = 'test-id';
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => $processingId,
            'source_file_path' => 'livestreams/source.mp4',
        ]);
        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            reasonCode: 'multiple_qualifying_speech_blocks',
            reasonMessage: 'Multiple speech blocks qualified.',
            speechSegments: [],
        );
        Storage::fake('local');
        Storage::disk('local')->put('livestreams/source.mp4', 'content');

        $this->artisan('livestream:create-sermon', ['processing_id' => $processingId])
            ->assertExitCode(1)
            ->expectsOutputToContain('No sermon segment found');
    }

    #[Test]
    public function it_no_longer_reports_success_when_the_post_review_flow_fails(): void
    {
        Mail::fake();
        Storage::fake('local');
        config(['queue.default' => 'sync']);

        $processingId = 'test-id';
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => $processingId,
            'source_file_path' => 'livestreams/source.mp4',
        ]);
        app(MediaProcessingRunTransitionService::class)->markForManualReview(
            $log,
            reasonCode: 'multiple_qualifying_speech_blocks',
            reasonMessage: 'Multiple speech blocks qualified.',
            speechSegments: [],
        );
        Storage::disk('local')->put('livestreams/source.mp4', 'content');

        LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create([
            'duration' => 1200,
            'start_time' => 100,
            'end_time' => 1300,
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildLivestreamPostReviewChainJobs')->andReturn([new AlwaysFailingJob]);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        $this->artisan('livestream:create-sermon', ['processing_id' => $processingId])
            ->assertExitCode(1)
            ->doesntExpectOutput('Resumed the canonical livestream sermon flow.');

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertNull(Sermon::query()->where('livestream_processing_id', $processingId)->first());
    }
}

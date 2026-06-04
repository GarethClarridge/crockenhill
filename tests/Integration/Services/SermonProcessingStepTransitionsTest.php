<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\Processing\SermonProcessingStepTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingStepTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private SermonProcessingStepTransitions $transitions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transitions = app(SermonProcessingStepTransitions::class);
    }

    #[Test]
    public function it_marks_a_step_as_started(): void
    {
        $processingId = $this->makeProcessingLog();

        $step = $this->transitions->markAsStarted($processingId, 'audio_extraction', 'Started extraction');

        $this->assertSame(ProcessingStatus::Started, $step->status);
        $this->assertSame('Started extraction', $step->message);
        $this->assertNotNull($step->started_at);
        $this->assertNull($step->completed_at);
    }

    #[Test]
    public function it_marks_a_step_as_completed(): void
    {
        $processingId = $this->makeProcessingLog();
        $this->transitions->markAsStarted($processingId, 'transcription');

        $step = $this->transitions->markAsCompleted($processingId, 'transcription', 'Done');

        $this->assertSame(ProcessingStatus::Completed, $step->status);
        $this->assertSame('Done', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_marks_a_step_as_failed(): void
    {
        $processingId = $this->makeProcessingLog();

        $step = $this->transitions->markAsFailed($processingId, 'ai_analysis', 'Something broke');

        $this->assertSame(ProcessingStatus::Failed, $step->status);
        $this->assertSame('Something broke', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_marks_a_step_as_skipped(): void
    {
        $processingId = $this->makeProcessingLog();

        $step = $this->transitions->markAsSkipped($processingId, 'thumbnail_generation', 'Not configured');

        $this->assertSame(ProcessingStatus::Skipped, $step->status);
        $this->assertSame('Not configured', $step->message);
    }

    #[Test]
    public function it_marks_a_step_as_cancelled_with_default_message(): void
    {
        $processingId = $this->makeProcessingLog();

        $step = $this->transitions->markAsCancelled($processingId, 'video_extraction');

        $this->assertSame(ProcessingStatus::Cancelled, $step->status);
        $this->assertSame('Cancelled by user', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_writes_to_the_same_row_on_repeated_calls_for_the_same_processing_id_and_step(): void
    {
        $processingId = $this->makeProcessingLog();
        $this->transitions->markAsStarted($processingId, 'audio_validation');
        $this->transitions->markAsCompleted($processingId, 'audio_validation', 'finished');

        $this->assertSame(1, SermonProcessingStep::query()
            ->where('processing_id', $processingId)
            ->where('step', 'audio_validation')
            ->count());
    }

    private function makeProcessingLog(): string
    {
        return MediaProcessingLog::factory()->create()->processing_id;
    }
}

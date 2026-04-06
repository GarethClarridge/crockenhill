<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingStepTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $data = [
            'processing_id' => 'uuid-123',
            'step' => 'transcription',
            'status' => ProcessingStatus::Started,
            'message' => 'Processing started',
            'started_at' => now(),
            'completed_at' => null,
        ];

        $step = new SermonProcessingStep($data);

        $this->assertEquals($data['processing_id'], $step->processing_id);
        $this->assertEquals($data['step'], $step->step);
        $this->assertEquals($data['status'], $step->status);
        $this->assertEquals($data['message'], $step->message);
    }

    #[Test]
    public function it_casts_datetime_fields(): void
    {
        $step = SermonProcessingStep::factory()->create();

        $this->assertInstanceOf(Carbon::class, $step->started_at);
        $this->assertInstanceOf(Carbon::class, $step->created_at);
        $this->assertInstanceOf(Carbon::class, $step->updated_at);
    }

    #[Test]
    public function it_marks_as_started(): void
    {
        $step = SermonProcessingStep::factory()->create();

        $step->markAsStarted('Test message');

        $this->assertEquals(ProcessingStatus::Started, $step->status);
        $this->assertEquals('Test message', $step->message);
        $this->assertNotNull($step->started_at);
    }

    #[Test]
    public function it_marks_as_completed(): void
    {
        $step = SermonProcessingStep::factory()->create([
            'status' => ProcessingStatus::Started,
        ]);

        $step->markAsCompleted('Done');

        $this->assertEquals(ProcessingStatus::Completed, $step->status);
        $this->assertEquals('Done', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_marks_as_failed(): void
    {
        $step = SermonProcessingStep::factory()->create([
            'status' => ProcessingStatus::Started,
        ]);

        $step->markAsFailed('Error occurred');

        $this->assertEquals(ProcessingStatus::Failed, $step->status);
        $this->assertEquals('Error occurred', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_marks_as_cancelled(): void
    {
        $step = SermonProcessingStep::factory()->create([
            'status' => ProcessingStatus::Started,
        ]);

        $step->markAsCancelled('User cancelled');

        $this->assertEquals(ProcessingStatus::Cancelled, $step->status);
        $this->assertEquals('User cancelled', $step->message);
        $this->assertNotNull($step->completed_at);
    }

    #[Test]
    public function it_marks_as_cancelled_with_default_message(): void
    {
        $step = SermonProcessingStep::factory()->create();

        $step->markAsCancelled();

        $this->assertEquals(ProcessingStatus::Cancelled, $step->status);
        $this->assertEquals('Cancelled by user', $step->message);
    }

    #[Test]
    public function it_reports_status_correctly(): void
    {
        $startedStep = SermonProcessingStep::factory()->create([
            'status' => ProcessingStatus::Started,
        ]);
        $this->assertTrue($startedStep->isStarted());
        $this->assertFalse($startedStep->isCompleted());
        $this->assertFalse($startedStep->isFailed());
        $this->assertFalse($startedStep->isCancelled());

        $completedStep = SermonProcessingStep::factory()->completed()->create();
        $this->assertFalse($completedStep->isStarted());
        $this->assertTrue($completedStep->isCompleted());
        $this->assertFalse($completedStep->isFailed());
        $this->assertFalse($completedStep->isCancelled());

        $failedStep = SermonProcessingStep::factory()->failed('Error')->create();
        $this->assertFalse($failedStep->isStarted());
        $this->assertFalse($failedStep->isCompleted());
        $this->assertTrue($failedStep->isFailed());
        $this->assertFalse($failedStep->isCancelled());

        $cancelledStep = SermonProcessingStep::factory()->cancelled()->create();
        $this->assertFalse($cancelledStep->isStarted());
        $this->assertFalse($cancelledStep->isCompleted());
        $this->assertFalse($cancelledStep->isFailed());
        $this->assertTrue($cancelledStep->isCancelled());
    }
}

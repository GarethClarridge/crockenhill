<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Jobs\SendCompletionNotification;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateSermonRecordTest extends TestCase
{
    use RefreshDatabase;

    private function createAnalysis(string $title = 'The Good Shepherd', ?string $series = 'John Series'): SermonAnalysis
    {
        return SermonAnalysis::create(
            title: $title,
            series: $series,
            reference: 'John 10:1-18',
            points: ['Point 1', 'Point 2'],
            summary: 'A sermon about the good shepherd.',
            transcript: 'This is the transcript content for testing.'
        );
    }

    private function createSermonWithTranscript(array $attributes = []): Sermon
    {
        $sermon = Sermon::factory()->create($attributes);

        // Store a transcript file so the analysis path is triggered
        $transcriptPath = "transcripts/sermon-{$sermon->id}.txt";
        Storage::put($transcriptPath, 'This is a test transcript with enough content for analysis.');
        $sermon->update(['transcript_file_path' => $transcriptPath]);

        return $sermon;
    }

    #[Test]
    public function it_updates_sermon_with_analysis_data(): void
    {
        Queue::fake();

        $sermon = $this->createSermonWithTranscript(['title' => 'Untitled Sermon']);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $analysis = $this->createAnalysis();

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new UpdateSermonRecord($sermon->id);
        $job->handle($mockService, new SermonRepository);

        $sermon->refresh();
        $this->assertEquals('The Good Shepherd', $sermon->title);
        $this->assertEquals('the-good-shepherd', $sermon->slug);
        $this->assertEquals('John Series', $sermon->series);
        $this->assertEquals('John 10:1-18', $sermon->reference);

        Queue::assertPushed(SendCompletionNotification::class);
    }

    #[Test]
    public function it_generates_unique_slug_with_collision(): void
    {
        Queue::fake();

        // Create existing sermon with the slug that would be generated
        Sermon::factory()->create(['slug' => 'the-good-shepherd']);

        $sermon = $this->createSermonWithTranscript(['title' => 'Untitled']);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $analysis = $this->createAnalysis();

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willReturn($analysis);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new UpdateSermonRecord($sermon->id);
        $job->handle($mockService, new SermonRepository);

        $sermon->refresh();
        $this->assertEquals('the-good-shepherd-1', $sermon->slug);
    }

    #[Test]
    public function it_falls_back_to_basic_analysis_when_transcript_missing(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'title' => 'Untitled',
            'transcript_file_path' => null,
        ]);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        // analyzeSermon should NOT be called when transcript is empty
        $mockService->expects($this->never())->method('analyzeSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new UpdateSermonRecord($sermon->id);
        $job->handle($mockService, new SermonRepository);

        $sermon->refresh();
        $this->assertNotEmpty($sermon->title);
        $this->assertNotEmpty($sermon->slug);
    }

    #[Test]
    public function it_falls_back_to_basic_analysis_when_analysis_fails(): void
    {
        Queue::fake();

        $sermon = $this->createSermonWithTranscript([
            'title' => 'A Good Title For Testing',
        ]);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        $mockService = $this->createMock(SermonAnalysisInterface::class);
        $mockService->expects($this->once())
            ->method('analyzeSermon')
            ->willThrowException(new \Exception('API rate limit exceeded'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new UpdateSermonRecord($sermon->id);
        $job->handle($mockService, new SermonRepository);

        $sermon->refresh();
        // Falls back to basic analysis — keeps existing good title
        $this->assertEquals('A Good Title For Testing', $sermon->title);
    }

    #[Test]
    public function it_throws_when_sermon_not_found(): void
    {
        $mockService = $this->createMock(SermonAnalysisInterface::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new UpdateSermonRecord(99999);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon not found');

        $job->handle($mockService, new SermonRepository);
    }

    #[Test]
    public function it_throws_when_processing_log_not_found(): void
    {
        $sermon = Sermon::factory()->create();

        $mockService = $this->createMock(SermonAnalysisInterface::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new UpdateSermonRecord($sermon->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing log not found');

        $job->handle($mockService, new SermonRepository);
    }

    #[Test]
    public function failed_method_applies_basic_update(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'A Meaningful Sermon Title',
        ]);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('info')->once();

        $job = new UpdateSermonRecord($sermon->id);
        $job->failed(new \Exception('AI service down'));

        $sermon->refresh();
        $this->assertEquals('A Meaningful Sermon Title', $sermon->title);
        $this->assertNotEmpty($sermon->slug);
    }

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $job = new UpdateSermonRecord(1);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals([30, 120, 300], $job->backoff());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\SermonAnalysis;
use App\Jobs\SendCompletionNotification;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateSermonRecordTest extends TestCase
{
    use RefreshDatabase;

    private function analysisArray(string $title = 'The Good Shepherd', ?string $series = 'John Series'): array
    {
        return SermonAnalysis::create(
            title: $title,
            series: $series,
            reference: 'John 10:1-18',
            points: ['Point 1', 'Point 2'],
            summary: 'A sermon about the good shepherd.',
            transcript: 'This is the transcript content for testing.'
        )->toArray();
    }

    #[Test]
    public function it_updates_sermon_with_stored_ai_analysis(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create(['title' => 'Untitled Sermon']);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => $this->analysisArray(),
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handleJob(new UpdateSermonRecord($sermon->id));

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

        Sermon::factory()->create(['slug' => 'the-good-shepherd']);

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => $this->analysisArray(),
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handleJob(new UpdateSermonRecord($sermon->id));

        $sermon->refresh();
        $this->assertEquals('the-good-shepherd-1', $sermon->slug);
    }

    #[Test]
    public function it_falls_back_to_basic_analysis_when_no_ai_analysis_stored(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'title' => 'Untitled',
            'transcript_file_path' => null,
        ]);
        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => null,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $this->handleJob(new UpdateSermonRecord($sermon->id));

        $sermon->refresh();
        $this->assertNotEmpty($sermon->title);
        $this->assertNotEmpty($sermon->slug);
    }

    #[Test]
    public function it_throws_when_sermon_not_found(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new UpdateSermonRecord(99999);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon not found');

        $this->handleJob($job);
    }

    #[Test]
    public function it_throws_when_processing_log_not_found(): void
    {
        $sermon = Sermon::factory()->create();

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new UpdateSermonRecord($sermon->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing log not found');

        $this->handleJob($job);
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

    private function handleJob(UpdateSermonRecord $job): void
    {
        $job->handle(
            app(MediaProcessingRunTransitionService::class),
            app(SermonRepository::class),
            app(SermonTranscriptReader::class),
        );
    }
}

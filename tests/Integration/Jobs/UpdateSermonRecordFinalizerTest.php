<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\SermonAnalysis;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\SermonTranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for TD-004B: UpdateSermonRecord must consume stored ai_analysis
 * rather than re-running analysis via the SermonAnalysisInterface.
 */
class UpdateSermonRecordFinalizerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_consumes_stored_ai_analysis_without_re_running_analysis(): void
    {
        Queue::fake();

        $storedAnalysis = SermonAnalysis::create(
            title: 'Stored AI Title',
            series: 'Stored Series',
            reference: 'Romans 8:1',
            points: ['Point A', 'Point B'],
            summary: 'A pre-computed summary.',
            transcript: 'Full transcript text.'
        );

        $sermon = Sermon::factory()->create(['title' => 'Untitled']);

        MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => $storedAnalysis->toArray(),
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handleJob(new UpdateSermonRecord($sermon->id));

        $sermon->refresh();
        $this->assertSame('Stored AI Title', $sermon->title);
        $this->assertSame('stored-ai-title', $sermon->slug);
        $this->assertSame('Stored Series', $sermon->series);
        $this->assertSame('Romans 8:1', $sermon->reference);
    }

    #[Test]
    public function it_falls_back_to_basic_analysis_when_no_ai_analysis_stored_and_no_transcript(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'title' => 'A Good Existing Title',
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

    private function handleJob(UpdateSermonRecord $job): void
    {
        $job->handle(
            app(MediaProcessingRunTransitionService::class),
            app(SermonRepository::class),
            app(SermonTranscriptReader::class),
        );
    }
}

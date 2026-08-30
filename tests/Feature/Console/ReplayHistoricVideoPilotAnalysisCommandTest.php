<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ReplayHistoricVideoPilotAnalysisCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    #[Test]
    public function it_replays_banked_pilot_analysis_without_calling_the_analysis_provider_and_is_idempotent(): void
    {
        $operation = $this->createHistoricImportOperation();
        $sermon = Sermon::factory()->create([
            'title' => 'Morning',
            'slug' => 'morning',
            'reference' => null,
            'series' => null,
            'duration' => null,
        ]);
        $processing = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Completed,
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2_400.0,
            'ai_analysis' => SermonAnalysis::create(
                title: 'Living Hope',
                series: '1 Peter',
                reference: '1 Peter 1:3-9',
                points: ['Hope'],
                summary: 'A banked pilot analysis.',
                transcript: str_repeat('Transcript ', 20),
            ),
        ]);
        $provider = $this->createMock(SermonAnalysisInterface::class);
        $provider->expects($this->never())->method('analyzeSermon');
        $this->app->instance(SermonAnalysisInterface::class, $provider);

        $arguments = [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$processing->processing_id],
        ];

        $this->artisan('historic-import:replay-video-pilot-analysis', $arguments)
            ->expectsOutputToContain('title, slug, reference, duration, series')
            ->expectsOutputToContain('without calling an analysis provider')
            ->assertSuccessful();

        $sermon->refresh();
        self::assertSame('Living Hope', $sermon->title);
        self::assertSame('living-hope', $sermon->slug);
        self::assertSame('1 Peter 1:3-9', $sermon->reference);
        self::assertSame('1 Peter', $sermon->series);
        self::assertSame(1_800.0, $sermon->duration);

        $this->artisan('historic-import:replay-video-pilot-analysis', $arguments)
            ->expectsOutputToContain('(none)')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_run_outside_the_named_operation_or_without_banked_analysis(): void
    {
        $operation = $this->createHistoricImportOperation();
        $otherOperation = $this->createHistoricImportOperation();
        $processing = MediaProcessingLog::factory()->completed()->create([
            'historic_import_operation_id' => $otherOperation->id,
        ]);

        $this->artisan('historic-import:replay-video-pilot-analysis', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$processing->processing_id],
        ])
            ->expectsOutputToContain('must be a completed run owned by the named historic operation')
            ->assertFailed();
    }
}

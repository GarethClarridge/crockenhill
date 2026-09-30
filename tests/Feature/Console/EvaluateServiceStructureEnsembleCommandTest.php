<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\Media\Audio\AudioTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class EvaluateServiceStructureEnsembleCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        Config::set('media-processing.storage.service_artifact_disk', 'artifacts');
        Config::set('concurrency.default', 'sync');
        $this->directory = storage_path('framework/testing/ensemble-evaluate-'.uniqid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function without_a_detector_it_builds_the_inputs_and_makes_no_draw(): void
    {
        $log = $this->serviceRun();

        $this->artisan('structure:ensemble-evaluate', ['manifest' => $this->manifest([$log->id], 3)])
            ->expectsOutputToContain('Plan: 3 sequences, 12 calls')
            ->assertSuccessful();

        $report = $this->report();
        $this->assertTrue($report['dry_run']);
        $this->assertSame([], $report['sequences']);
        $this->assertArrayHasKey((string) $log->id, $report['inputs']);
        $this->assertSame([], File::glob($this->directory.'/manifest-report-draws/*slot*'));
    }

    #[Test]
    public function it_composes_cuts_and_scores_each_sequence_without_writing_to_the_run(): void
    {
        $log = $this->serviceRun();
        $metadata = $log->fresh()->processing_metadata?->toArray();

        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 2),
            '--detector' => 'mock',
        ])->assertSuccessful();

        $report = $this->report();
        $this->assertCount(2, $report['sequences']);
        $this->assertSame(8, $report['calls']);
        $this->assertSame(4, $report['sequences'][0]['valid_votes']);
        $this->assertSame(['gated', 'as_written'], array_keys($report['sequences'][0]['cut']));
        $this->assertIsArray($report['sequences'][0]['score']);
        $this->assertSame(2, $report['summary']['batch']['sequences']);
        $this->assertCount(8, File::glob($this->directory.'/manifest-report-draws/*slot*'));
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame($metadata, $log->fresh()->processing_metadata?->toArray());
        $this->assertSame(1, MediaProcessingLog::query()->count());
    }

    /** A draw with no reported usage is charged the worst-case reserve, so the cap holds. */
    #[Test]
    public function it_stops_before_a_sequence_whose_worst_case_would_cross_the_spending_cap(): void
    {
        $log = $this->serviceRun();

        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 3, maxSpend: 0.2, reserve: 0.025),
            '--detector' => 'mock',
        ])->expectsOutputToContain('Stopped early: max_spend')->assertFailed();

        $report = $this->report();
        $this->assertCount(2, $report['sequences']);
        $this->assertSame('max_spend', $report['stop_reason']);
        $this->assertLessThanOrEqual(0.2, $report['spent_usd']);
    }

    #[Test]
    public function it_stops_at_the_call_cap(): void
    {
        $log = $this->serviceRun();

        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 3, maxCalls: 6),
            '--detector' => 'mock',
        ])->assertFailed();

        $this->assertSame('max_calls', $this->report()['stop_reason']);
        $this->assertSame(4, $this->report()['calls']);
    }

    #[Test]
    public function a_resumed_report_keeps_its_sequences_and_spend_against_the_caps(): void
    {
        $log = $this->serviceRun();
        $manifest = $this->manifest([$log->id], 3);
        $this->failingDetector(everyNthCall: 1);

        $this->artisan('structure:ensemble-evaluate', ['manifest' => $manifest, '--detector' => 'mock'])->assertFailed();
        $this->assertCount(2, $this->report()['sequences']);

        $this->app->instance(ServiceStructureInterface::class, app(MockServiceStructureService::class));
        $this->artisan('structure:ensemble-evaluate', ['manifest' => $manifest, '--detector' => 'mock', '--resume' => true])
            ->expectsOutputToContain('Resuming: 2 sequences kept, 8 calls')
            ->assertSuccessful();

        $report = $this->report();
        $this->assertSame([1, 2, 3], array_column($report['sequences'], 'sequence'));
        $this->assertSame([0, 0, 4], array_column($report['sequences'], 'valid_votes'));
        $this->assertSame(12, $report['calls']);
        $this->assertSame('repeated_unavailable_draws', $report['resumed_after']);
        $this->assertNull($report['stop_reason']);
        $this->assertCount(2, $report['code_versions']);
    }

    #[Test]
    public function a_resume_under_a_changed_manifest_is_refused(): void
    {
        $log = $this->serviceRun();
        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 2, maxCalls: 4),
            '--detector' => 'mock',
        ])->assertFailed();

        $this->expectExceptionMessage('different manifest');
        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 2, maxCalls: 8),
            '--detector' => 'mock',
            '--resume' => true,
        ]);
    }

    /** A stray server error leaves three votes and is noise; an outage leaves fewer and stops the run. */
    #[Test]
    public function only_sequences_left_under_three_valid_votes_by_lost_draws_stop_the_run(): void
    {
        $log = $this->serviceRun();
        $this->failingDetector(everyNthCall: 4);

        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 3),
            '--detector' => 'mock',
        ])->assertSuccessful();
        $this->assertCount(3, $this->report()['sequences']);

        $this->failingDetector(everyNthCall: 2);

        $this->artisan('structure:ensemble-evaluate', [
            'manifest' => $this->manifest([$log->id], 3),
            '--detector' => 'mock',
        ])->assertFailed();
        $this->assertSame('repeated_unavailable_draws', $this->report()['stop_reason']);
        $this->assertCount(2, $this->report()['sequences']);
    }

    private function failingDetector(int $everyNthCall): void
    {
        $mock = app(MockServiceStructureService::class);
        $detector = new class($mock, $everyNthCall) implements ServiceStructureInterface
        {
            private int $calls = 0;

            public function __construct(private readonly MockServiceStructureService $mock, private readonly int $everyNthCall) {}

            public function detect(
                ChurchServiceTranscript $transcript,
                array $oosItems,
                ?string $processingId = null,
                array $feedback = [],
                ?AudioTimeline $audioTimeline = null,
                ?string $model = null,
            ): ServiceStructure {
                if ($this->calls++ % $this->everyNthCall === 0) {
                    throw new RuntimeException('Server error (HTTP 520) occurred.');
                }

                return $this->mock->detect($transcript, $oosItems, $processingId, $feedback, $audioTimeline, $model);
            }
        };

        $this->app->instance(ServiceStructureInterface::class, $detector);
    }

    private function serviceRun(): MediaProcessingLog
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 60.0, 'text' => 'Good morning and welcome.'],
            ['start' => 420.0, 'end' => 590.0, 'text' => 'Our reading is from John chapter three.'],
            ['start' => 600.0, 'end' => 2200.0, 'text' => 'Please turn with me to the passage we have just read.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
        Storage::disk('artifacts')->put('service-transcripts/run.json', json_encode($transcript->toArray(), JSON_THROW_ON_ERROR));
        AudioTimelineFixture::put('artifacts', 'service-transcripts/run.classes.json');

        return MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => ChurchService::factory()->create()->id,
            'audio_timeline_path' => 'service-transcripts/run.classes.json',
            'processing_metadata' => ['service_transcript_path' => 'service-transcripts/run.json'],
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2200.0,
        ]);
    }

    /** @param  list<int>  $runs */
    private function manifest(array $runs, int $sequences, int $maxCalls = 280, float $maxSpend = 3.0, float $reserve = 0.025): string
    {
        $truth = $this->directory.'/truth.json';
        $prices = $this->directory.'/prices.json';
        $manifest = $this->directory.'/manifest.json';

        File::put($truth, json_encode(['runs' => [(string) $runs[0] => [
            ['type' => 'sermon', 'start' => 600, 'end' => 2210, 'basis' => 'operator'],
        ]]], JSON_THROW_ON_ERROR));
        File::put($prices, json_encode(['models' => [
            'gpt-5.6-luna' => ['input' => 0.2, 'cached_input' => 0.02, 'output' => 1.2],
            'gpt-6-luna' => ['input' => 0.1, 'cached_input' => 0.01, 'output' => 0.5],
        ]], JSON_THROW_ON_ERROR));
        File::put($manifest, json_encode([
            'truth' => $truth,
            'price_snapshot' => $prices,
            'max_calls' => $maxCalls,
            'max_spend_usd' => $maxSpend,
            'reserve_per_call_usd' => $reserve,
            'sets' => [['name' => 'batch', 'runs' => $runs, 'sequences' => $sequences]],
        ], JSON_THROW_ON_ERROR));

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return json_decode((string) File::get($this->directory.'/manifest-report.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}

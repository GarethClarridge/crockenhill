<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\ExtractForCorpusRerun;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Jobs\RenderDeferredCuts;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * `historic-import:rerun-render`: the operator's between-batches render of the cuts Tier C
 * deferred (plan §4.0, "cut now, render later").
 */
class RenderForCorpusRerunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = Mockery::mock(HistoricStagingContextRegistry::class);
        $registry->shouldReceive('within')
            ->andReturnUsing(static fn (HistoricStagingContext $context, Closure $callback): mixed => $callback());
        $registry->shouldReceive('isActive')->andReturn(true);
        $this->app->instance(HistoricStagingContextRegistry::class, $registry);

        $this->directory = 'rerun-render-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(storage_path('app/private/'.$this->directory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        Bus::fake();
        $this->extractedRun();

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready for render')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_dispatches_the_render_on_the_ffmpeg_queue(): void
    {
        Bus::fake();
        $run = $this->extractedRun();

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('1 dispatched, 0 refused')
            ->assertSuccessful();

        Bus::assertDispatched(
            RenderDeferredCuts::class,
            static fn (RenderDeferredCuts $job): bool => $job->queue === config('media-processing.historic_import.stages.ffmpeg.queue'),
        );

        $stamp = $run->refresh()->corpusRerunStamps()[0];
        $this->assertNotNull($stamp['render_dispatched_at']);
        $this->assertSame(ExtractForCorpusRerun::RENDER_DEFERRED, $stamp['render'], 'Only the job may say the render happened.');
    }

    #[Test]
    public function it_refuses_a_run_already_rendered(): void
    {
        Bus::fake();
        $this->extractedRun(['render' => ExtractForCorpusRerun::RENDER_RENDERED, 'rendered_at' => '2026-09-27T20:00:00+00:00']);

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('already rendered at 2026-09-27T20:00:00+00:00')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_media_tier_c_has_not_cut(): void
    {
        Bus::fake();
        $this->extractedRun(['media' => 'deferred', 'render' => null]);

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('Tier C has not cut this run with its render deferred')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * A run parked for its held sermon has not finished Tier C: its re-cut is still to come,
     * and it too defers its render.
     */
    #[Test]
    public function it_refuses_a_run_whose_tier_c_has_not_finished(): void
    {
        Bus::fake();
        $run = $this->extractedRun();
        $run->forceFill(['status' => ProcessingStatus::Failed])->save();

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('Tier C has not finished (run is failed)')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_on_a_commit_other_than_the_snapshots(): void
    {
        Bus::fake();
        $this->extractedRun();
        $path = storage_path('app/private/'.$this->snapshotPath());
        $snapshot = json_decode((string) file_get_contents($path), true);
        $snapshot['git_commit'] = str_repeat('0', 40);
        file_put_contents($path, json_encode($snapshot));

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('render on the commit that cut it')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_an_excluded_run(): void
    {
        Bus::fake();
        $run = $this->extractedRun();
        $run->putExclusion(MediaProcessingLog::EXCLUSION_REASON_PRIVATE_OCCASION, ['note' => 'Funeral.']);

        $this->artisan('historic-import:rerun-render', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run is excluded')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * A member whose Tier C finished on the running commit with its render deferred.
     *
     * @param  array<string, mixed>  $stampOverrides
     */
    private function extractedRun(array $stampOverrides = []): MediaProcessingLog
    {
        $operation = $this->createHistoricImportOperation();
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $this->stagingContext()->toArray(),
                ],
            ],
        ]);

        $this->artisan('historic-import:rerun-snapshot', ['runs' => [$run->id], '--output' => $this->snapshotPath()])
            ->assertSuccessful();
        $commit = json_decode((string) file_get_contents(storage_path('app/private/'.$this->snapshotPath())), true)['git_commit'];

        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'git_commit' => $commit,
            'media' => ExtractForCorpusRerun::MEDIA_EXTRACTED,
            'render' => ExtractForCorpusRerun::RENDER_DEFERRED,
            'media_recorded_at' => '2026-09-27T19:30:00+00:00',
            'worker_commit' => $commit,
            'extraction_dispatched_at' => '2026-09-27T19:40:00+00:00',
            ...$stampOverrides,
        ]);

        return $run->refresh();
    }

    private function stagingContext(): HistoricStagingContext
    {
        return new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/corpus-rerun',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}

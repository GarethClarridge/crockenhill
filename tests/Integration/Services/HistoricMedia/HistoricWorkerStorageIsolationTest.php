<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Jobs\AutoPublishServiceSection;
use App\Jobs\DetectServiceStructure;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HistoricStagingCanaryJob;
use Tests\TestCase;

class HistoricWorkerStorageIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-worker-staging-'.uniqid();
        File::ensureDirectoryExists($this->temporaryDirectory);

        config([
            'filesystems.disks.historic_staging' => [
                'driver' => 'local',
                'root' => $this->temporaryDirectory.'/staging',
                'visibility' => 'private',
                'throw' => true,
            ],
            'filesystems.disks.public' => [
                'driver' => 'local',
                'root' => $this->temporaryDirectory.'/public',
                'visibility' => 'public',
                'url' => 'https://example.test/storage',
            ],
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function queued_dispatches_write_only_below_the_resolved_batch_root(): void
    {
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        // This is the configuration a stale worker would otherwise use. The
        // queued payload, rather than its boot-time configuration, must decide
        // where every durable and temporary pipeline write lands.
        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.temp_disk' => 'public',
            'thumbnail-generation.storage.disk' => 'public',
            'thumbnail-generation.processing.temp_disk' => 'public',
        ]);

        config(['queue.default' => 'database']);

        app(HistoricStagingContextRegistry::class)->within(
            $context,
            static fn (): mixed => Queue::push(new HistoricStagingCanaryJob),
        );

        $this->artisan('queue:work database --once --queue=default')
            ->assertExitCode(0);

        $batchRoot = $this->temporaryDirectory.'/staging/'.$context->batchRoot;

        foreach ([
            'canary/sermon.txt',
            'canary/transcript.txt',
            'canary/temp.txt',
            'canary/thumbnail.txt',
            'canary/thumbnail-temp.txt',
        ] as $path) {
            self::assertFileExists("{$batchRoot}/{$path}");
            self::assertFileDoesNotExist("{$this->temporaryDirectory}/staging/{$path}");
            self::assertFileDoesNotExist("{$this->temporaryDirectory}/public/{$path}");
        }
    }

    #[Test]
    public function a_synchronously_executed_job_does_not_release_the_surrounding_context(): void
    {
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('e', 64),
            str_repeat('f', 64),
        );

        config(['queue.default' => 'sync']);

        $registry = app(HistoricStagingContextRegistry::class);

        /**
         * The importer keeps working after it dispatches — it still has concat
         * output to delete below the batch root, and further files to dispatch.
         * A job that happens to run inline must not take the batch's storage
         * configuration down with it when it finishes.
         */
        $observed = $registry->within($context, static function () use ($registry): array {
            Queue::push(new HistoricStagingCanaryJob);

            return [
                'payload' => $registry->queuePayload(),
                'sermon_disk' => config('media-processing.storage.sermon_disk'),
            ];
        });

        self::assertSame(
            ['historic_staging_context' => $context->toArray()],
            $observed['payload'],
            'the surrounding batch context was released by the inline job',
        );
        self::assertSame('historic_staging', $observed['sermon_disk']);
        self::assertFileExists(
            $this->temporaryDirectory.'/staging/'.$context->batchRoot.'/canary/sermon.txt',
        );
    }

    /**
     * A job dispatched from inside another job never passes through the chain
     * builder, so without this it keeps its default queue and is served by the
     * weekly worker pools instead of the calibrated historic ones.
     */
    #[Test]
    public function work_dispatched_from_inside_a_job_is_routed_to_its_calibrated_stage(): void
    {
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('1', 64),
            str_repeat('2', 64),
        );
        $throughput = app(HistoricProcessingThroughput::class);

        self::assertNull(
            $throughput->historicQueueFor(DetectServiceStructure::class),
            'the weekly path must keep its own routing',
        );

        app(HistoricStagingContextRegistry::class)->within($context, static function () use ($throughput): void {
            self::assertSame(
                (string) config('media-processing.historic_import.stages.llm.queue'),
                $throughput->historicQueueFor(DetectServiceStructure::class),
            );
            self::assertSame(
                (string) config('media-processing.historic_import.stages.ffmpeg.queue'),
                $throughput->historicQueueFor(PrepareSectionPublicationCandidates::class),
            );
            self::assertSame(
                (string) config('media-processing.historic_import.stages.orchestration.queue'),
                $throughput->historicQueueFor(AutoPublishServiceSection::class),
            );
        });
    }

    #[Test]
    public function queued_jobs_carry_the_approved_staging_and_manifest_context(): void
    {
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('c', 64),
            str_repeat('d', 64),
        );

        config(['queue.default' => 'database']);

        app(HistoricStagingContextRegistry::class)->within(
            $context,
            static fn (): mixed => Queue::push(new HistoricStagingCanaryJob),
        );

        $payload = json_decode((string) \DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($context->toArray(), $payload['historic_staging_context']);
    }
}

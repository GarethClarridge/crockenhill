<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Jobs\GenerateThumbnail;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueueWorkerCoverageTest extends TestCase
{
    #[Test]
    public function docker_compose_worker_consumes_all_required_processing_queues(): void
    {
        $queues = $this->extractQueuesFromFile(base_path('docker-compose.yml'));

        foreach ($this->requiredQueues() as $requiredQueue) {
            $this->assertContains(
                $requiredQueue,
                $queues,
                "docker-compose queue worker is missing required queue [{$requiredQueue}]"
            );
        }
    }

    #[Test]
    public function production_horizon_supervisors_consume_all_required_processing_queues(): void
    {
        $queues = $this->productionHorizonQueues();

        foreach ($this->requiredQueues() as $requiredQueue) {
            $this->assertContains(
                $requiredQueue,
                $queues,
                "production Horizon supervisors are missing required queue [{$requiredQueue}]"
            );
        }
    }

    /**
     * The historic execution profile records each stage's worker width. If
     * compose sized its pools from a different variable than the profile reads,
     * the report would describe a pass that never happened.
     */
    #[Test]
    public function historic_worker_pools_are_sized_from_the_variables_the_fingerprint_records(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $this->assertNotFalse($compose);

        foreach (['FFMPEG', 'WHISPER', 'LLM', 'ORCHESTRATION'] as $stage) {
            $this->assertStringContainsString(
                'replicas: ${HISTORIC_MEDIA_WORKERS_'.$stage.':-1}',
                $compose,
                "The historic {$stage} worker pool is not sized from HISTORIC_MEDIA_WORKERS_{$stage}."
            );
        }

        $fingerprint = app(HistoricProcessingThroughput::class)->fingerprint();

        $this->assertSame(
            ['ffmpeg', 'llm', 'orchestration', 'whisper'],
            array_keys($fingerprint),
            'Every historic stage must contribute its width to the fingerprint.'
        );
    }

    #[Test]
    public function production_supervisord_runs_horizon(): void
    {
        $supervisord = file_get_contents(base_path('docker/production/supervisord.conf'));

        $this->assertNotFalse($supervisord);
        $this->assertStringContainsString(
            'artisan horizon',
            $supervisord,
            'Production supervisord must run Horizon — otherwise the queues guarded by this test are never consumed.'
        );
        $this->assertStringNotContainsString(
            'queue:work',
            $supervisord,
            'Raw queue:work workers must not run alongside Horizon in production.'
        );
    }

    #[Test]
    public function thumbnail_generation_jobs_inherit_their_parent_pipeline_queue(): void
    {
        $job = new GenerateThumbnail(MediaProcessingLog::factory()->video()->processing()->make());

        $this->assertNull($job->connection);
        $this->assertNull($job->queue);
    }

    /**
     * @return array<int, string>
     */
    private function requiredQueues(): array
    {
        $required = [
            (string) config('media-processing.queues.default'),
            (string) config('media-processing.queues.audio'),
            (string) config('media-processing.queues.video'),
            (string) config('media-processing.queues.livestream'),
            (string) config('media-processing.speaker_identification.queue'),
            (string) config('media-processing.historic_import.stages.ffmpeg.queue'),
            (string) config('media-processing.historic_import.stages.whisper.queue'),
            (string) config('media-processing.historic_import.stages.llm.queue'),
            (string) config('media-processing.historic_import.stages.orchestration.queue'),
        ];

        return array_values(array_unique(array_filter($required)));
    }

    /**
     * Queues consumed in production: each supervisor listed for the production
     * environment, with its options merged over the Horizon defaults.
     *
     * @return array<int, string>
     */
    private function productionHorizonQueues(): array
    {
        $defaults = (array) config('horizon.defaults');
        $production = (array) config('horizon.environments.production');

        $this->assertNotEmpty($production, 'No Horizon supervisors are configured for production.');

        $queues = [];

        foreach ($production as $supervisor => $overrides) {
            $options = array_merge((array) ($defaults[$supervisor] ?? []), (array) $overrides);

            $queues = array_merge($queues, (array) ($options['queue'] ?? []));
        }

        return array_values(array_unique($queues));
    }

    /**
     * @return array<int, string>
     */
    private function extractQueuesFromFile(string $path): array
    {
        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Failed to read queue worker definition at {$path}");

        preg_match_all('/--queue=([^\s]+)/', $content, $matches);

        $this->assertNotEmpty($matches[1], "No --queue definition found in {$path}");

        $queues = [];
        foreach ($matches[1] as $queueList) {
            foreach (array_map('trim', explode(',', $this->resolveComposeDefaults($queueList))) as $queue) {
                if ($queue !== '') {
                    $queues[] = $queue;
                }
            }
        }

        return array_values(array_unique($queues));
    }

    /**
     * Historic worker queues are named through `${VAR:-default}` so calibration
     * can rename them without editing compose. This test cares about the
     * committed default, which is what runs when the variable is unset.
     */
    private function resolveComposeDefaults(string $value): string
    {
        return (string) preg_replace('/\$\{[A-Z0-9_]+:-([^}]*)\}/', '$1', $value);
    }
}

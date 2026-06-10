<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Jobs\GenerateThumbnail;
use App\Models\MediaProcessingLog;
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
            foreach (array_map('trim', explode(',', $queueList)) as $queue) {
                if ($queue !== '') {
                    $queues[] = $queue;
                }
            }
        }

        return array_values(array_unique($queues));
    }
}

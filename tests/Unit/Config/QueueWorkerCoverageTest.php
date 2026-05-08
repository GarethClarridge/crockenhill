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
    public function production_supervisor_worker_consumes_all_required_processing_queues(): void
    {
        $queues = $this->extractQueuesFromFile(base_path('docker/production/supervisord.conf'));

        foreach ($this->requiredQueues() as $requiredQueue) {
            $this->assertContains(
                $requiredQueue,
                $queues,
                "production supervisor worker is missing required queue [{$requiredQueue}]"
            );
        }
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

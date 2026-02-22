<?php

namespace Tests\Unit\Config;

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

    /**
     * @return array<int, string>
     */
    private function requiredQueues(): array
    {
        $required = [
            (string) config('media-processing.queues.default'),
            (string) config('media-processing.queues.processing'),
            (string) config('media-processing.queues.audio'),
            (string) config('media-processing.queues.video'),
            (string) config('media-processing.queues.livestream'),
            (string) config('media-processing.queues.livestream_audio'),
            (string) config('media-processing.speaker_identification.queue'),
            (string) config('thumbnail-generation.queue.name', 'thumbnails'),
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

        preg_match('/--queue=([^\s]+)/', $content, $matches);

        $this->assertArrayHasKey(1, $matches, "No --queue definition found in {$path}");

        return array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
    }
}

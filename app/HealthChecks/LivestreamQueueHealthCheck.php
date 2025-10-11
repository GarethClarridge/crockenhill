<?php

namespace App\HealthChecks;

use App\Models\MediaProcessingLog;
use Illuminate\Contracts\Support\Arrayable;

class LivestreamQueueHealthCheck implements Arrayable
{
    /**
     * The name of the health check.
     */
    public function name(): string
    {
        return 'livestream-queue';
    }

    public function run(): array
    {
        try {
            $queueName = config('media-processing.queue.name');

            // Check if there are any stuck processing jobs
            $stuckJobs = MediaProcessingLog::where('status', 'processing')
                ->where('started_at', '<', now()->subHours(4))
                ->count();

            if ($stuckJobs > 0) {
                return [
                    'status' => 'degraded',
                    'message' => "Found {$stuckJobs} potentially stuck processing jobs",
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Check for failed jobs in the last hour
            $recentFailures = MediaProcessingLog::where('status', 'failed')
                ->where('created_at', '>', now()->subHour())
                ->count();

            if ($recentFailures > 5) {
                return [
                    'status' => 'degraded',
                    'message' => "High failure rate: {$recentFailures} failed jobs in the last hour",
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Check pending jobs count
            $pendingJobs = MediaProcessingLog::where('status', 'pending')->count();

            if ($pendingJobs > 10) {
                return [
                    'status' => 'degraded',
                    'message' => "High queue backlog: {$pendingJobs} pending jobs",
                    'timestamp' => now()->toISOString(),
                ];
            }

            return [
                'status' => 'healthy',
                'message' => "Livestream processing queue is healthy. {$pendingJobs} pending jobs.",
                'timestamp' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "Queue health check failed: {$e->getMessage()}",
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Convert the health check to an array.
     */
    public function toArray(): array
    {
        return $this->run();
    }
}

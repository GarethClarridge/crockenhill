<?php

namespace App\HealthChecks;

use App\Models\MediaProcessingLog;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;

class SermonProcessingQueueHealthCheck implements Arrayable
{
    /**
     * The name of the health check.
     */
    public function name(): string
    {
        return 'sermon-processing-queue';
    }

    /**
     * Run the health check.
     */
    public function run(): array
    {
        try {
            // Check if there are jobs stuck in processing for too long
            $stuckJobs = MediaProcessingLog::processing()
                ->where('updated_at', '<', now()->subHours(2))
                ->count();

            $pendingJobs = MediaProcessingLog::pending()->count();
            $processingJobs = MediaProcessingLog::processing()->count();

            $status = 'healthy';
            $issues = [];

            if ($stuckJobs > 0) {
                $status = 'degraded';
                $issues[] = "{$stuckJobs} jobs appear to be stuck in processing";
            }

            if ($pendingJobs > 10) {
                $status = 'degraded';
                $issues[] = "{$pendingJobs} jobs pending - queue may be backed up";
            }

            if ($processingJobs > 5) {
                $status = 'degraded';
                $issues[] = "{$processingJobs} jobs currently processing - high load";
            }

            return [
                'status' => $status,
                'message' => empty($issues) ? 'Queue is operating normally' : implode(', ', $issues),
                'metrics' => [
                    'stuck_jobs' => $stuckJobs,
                    'pending_jobs' => $pendingJobs,
                    'processing_jobs' => $processingJobs,
                ],
                'issues' => $issues,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            Log::warning('Sermon processing queue health check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to check queue health: '.$e->getMessage(),
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

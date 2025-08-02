<?php

namespace App\HealthChecks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Illuminate\Support\Facades\Queue;
use App\Models\LivestreamProcessingLog;

class LivestreamQueueHealthCheck extends Check
{
    public function run(): Result
    {
        $result = Result::make();

        try {
            $queueName = config('livestream-processing.queue.name');
            
            // Check if there are any stuck processing jobs
            $stuckJobs = LivestreamProcessingLog::where('status', 'processing')
                ->where('started_at', '<', now()->subHours(4))
                ->count();

            if ($stuckJobs > 0) {
                return $result->warning("Found {$stuckJobs} potentially stuck processing jobs");
            }

            // Check for failed jobs in the last hour
            $recentFailures = LivestreamProcessingLog::where('status', 'failed')
                ->where('created_at', '>', now()->subHour())
                ->count();

            if ($recentFailures > 5) {
                return $result->warning("High failure rate: {$recentFailures} failed jobs in the last hour");
            }

            // Check pending jobs count
            $pendingJobs = LivestreamProcessingLog::where('status', 'pending')->count();
            
            if ($pendingJobs > 10) {
                return $result->warning("High queue backlog: {$pendingJobs} pending jobs");
            }

            return $result->ok("Livestream processing queue is healthy. {$pendingJobs} pending jobs.");

        } catch (\Exception $e) {
            return $result->failed("Queue health check failed: {$e->getMessage()}");
        }
    }
}
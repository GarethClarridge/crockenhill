<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\AutoPublishServiceSection;
use App\Jobs\DetectServiceStructure;
use App\Jobs\GenerateRmsLog;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The queue policies a multi-day bulk run depends on.
 *
 * These held inconsistently before the historic-video bulk work: the section
 * publication lock expired six times sooner than the job it guarded could
 * finish, and two of the three stages that bill a provider retried with no
 * delay at all. Neither is visible in a single run and both compound across
 * hundreds.
 */
class PipelineQueueSafetyTest extends TestCase
{
    /**
     * An overlap lock that expires while its job is still running is not a lock.
     * `PrepareSectionPublicationCandidates` allows 1800 seconds and expired
     * after 300, so any extraction past five minutes — a full service, routinely
     * — left the door open behind it.
     */
    #[Test]
    public function every_overlap_lock_outlives_the_job_it_guards(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->make(['id' => 1]);

        foreach ([
            PrepareSectionPublicationCandidates::class => new PrepareSectionPublicationCandidates($log),
            DetectServiceStructure::class => new DetectServiceStructure($log),
            MatchSongsFromTranscript::class => new MatchSongsFromTranscript($log),
        ] as $name => $job) {
            $middleware = collect($job->middleware())
                ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);

            $this->assertInstanceOf(WithoutOverlapping::class, $middleware, $name);
            $this->assertGreaterThan(
                $job->timeout,
                $this->expiresAfter($middleware),
                "{$name} releases its lock while the job can still be running.",
            );
        }
    }

    #[Test]
    public function the_section_publication_jobs_hold_their_locks_for_their_full_timeout(): void
    {
        foreach ([
            AutoPublishServiceSection::class => new AutoPublishServiceSection(1),
            PublishApprovedServiceSection::class => new PublishApprovedServiceSection(1),
        ] as $name => $job) {
            $middleware = collect($job->middleware())
                ->first(fn (object $item): bool => $item instanceof WithoutOverlapping);

            $this->assertInstanceOf(WithoutOverlapping::class, $middleware, $name);
            $this->assertGreaterThan($job->timeout, $this->expiresAfter($middleware), $name);
        }
    }

    /**
     * A stage that bills a provider must wait before retrying. Retrying at once
     * burns all three attempts inside a rate-limit window and pays for each
     * request that reached the model before failing.
     */
    #[Test]
    public function every_paid_stage_backs_off_between_attempts(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->make(['id' => 1]);

        foreach ([
            ProcessTranscriptWithAI::class => new ProcessTranscriptWithAI($log),
            DetectServiceStructure::class => new DetectServiceStructure($log),
            MatchSongsFromTranscript::class => new MatchSongsFromTranscript($log),
        ] as $name => $job) {
            $backoff = $job->backoff();

            $this->assertNotEmpty($backoff, "{$name} retries a paid request with no delay.");
            $this->assertSame(
                $backoff,
                array_values(array_unique($backoff)),
                "{$name} does not increase its delay between attempts.",
            );
            $this->assertGreaterThan(0, $backoff[0], $name);
        }
    }

    #[Test]
    public function rms_generation_process_finishes_before_its_queue_job_timeout(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->make(['id' => 1]);
        $job = new GenerateRmsLog($log);

        $this->assertGreaterThan(VideoSegmentationService::RmsProcessTimeoutSeconds, $job->timeout);
    }

    private function expiresAfter(WithoutOverlapping $middleware): int
    {
        $property = new ReflectionProperty($middleware, 'expiresAfter');

        return (int) $property->getValue($middleware);
    }
}

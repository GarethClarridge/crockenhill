<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class AwaitHistoricSermonVideoStorageTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_releases_until_the_operation_owned_storage_job_settles(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        Log::shouldReceive('info')->once();

        $job = new AwaitHistoricSermonVideoStorage($log);
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(300);
    }

    #[Test]
    public function it_allows_the_chain_to_continue_after_storage_completes(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'completed',
            'attempts' => 1,
            'dispatched_at' => now(),
            'settled_at' => now(),
        ]);

        Log::shouldReceive('info')->once();

        $job = new AwaitHistoricSermonVideoStorage($log);
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
    }

    #[Test]
    public function it_fails_closed_when_owned_storage_failed_permanently(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'failed',
            'attempts' => 3,
            'dispatched_at' => now(),
            'settled_at' => now(),
        ]);

        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed permanently');

        (new AwaitHistoricSermonVideoStorage($log))->handle();
    }

    #[Test]
    public function it_bounds_the_wait_so_an_unsettled_run_cannot_poll_forever(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->assertLessThanOrEqual(
            now()->addHours(3)->addMinute()->getTimestamp(),
            (new AwaitHistoricSermonVideoStorage($log))->retryUntil()->getTimestamp(),
            'The storage gate must expire rather than hold a chain slot indefinitely.',
        );
    }

    #[Test]
    public function it_fails_the_run_truthfully_when_the_bounded_wait_expires(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $nestedJob = HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 2,
            'dispatched_at' => now(),
        ]);

        (new AwaitHistoricSermonVideoStorage($log))->failed(
            new \RuntimeException('Attempted too many times.'),
        );

        $this->assertSame('failed', $nestedJob->refresh()->state);
        $this->assertNotNull($nestedJob->settled_at);

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertStringContainsString('did not settle', (string) $log->error_message);
    }
}

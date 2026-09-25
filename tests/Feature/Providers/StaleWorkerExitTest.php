<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Support\WorkerCode;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A worker keeps the code it booted on, so after a commit it runs old code under the new
 * commit's name (the corpus re-run stamps rounds with the command's commit). A worker whose
 * checkout has moved on exits before taking its next job, and its container restarts it.
 */
class StaleWorkerExitTest extends TestCase
{
    private const BOOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const LATER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = storage_path('framework/testing/stale-worker-'.getmypid());
        File::ensureDirectoryExists($this->checkout.'/.git');
        $this->app->instance(WorkerCode::class, new WorkerCode($this->checkout));
        app('queue.worker')->shouldQuit = false;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);
        app('queue.worker')->shouldQuit = false;

        parent::tearDown();
    }

    #[Test]
    public function a_worker_exits_before_its_next_job_once_the_checkout_moves_past_its_boot_commit(): void
    {
        WorkerCode::recordBoot(self::BOOT);
        File::put($this->checkout.'/.git/HEAD', self::LATER."\n");

        self::assertFalse(Event::until(new Looping('redis', 'default')), 'A stale worker must not take a job.');
        self::assertTrue(app('queue.worker')->shouldQuit, 'A stale worker quits so its container restarts it on current code.');
    }

    #[Test]
    public function a_worker_on_the_current_commit_carries_on(): void
    {
        WorkerCode::recordBoot(self::BOOT);
        File::put($this->checkout.'/.git/HEAD', self::BOOT."\n");

        self::assertNotFalse(Event::until(new Looping('redis', 'default')));
        self::assertFalse(app('queue.worker')->shouldQuit);
    }

    /**
     * A deployment without a readable checkout has no commit to compare, so it is left alone.
     */
    #[Test]
    public function a_worker_with_no_readable_commit_carries_on(): void
    {
        WorkerCode::recordBoot(null);
        File::put($this->checkout.'/.git/HEAD', self::LATER."\n");

        self::assertNotFalse(Event::until(new Looping('redis', 'default')));
        self::assertFalse(app('queue.worker')->shouldQuit);
    }
}

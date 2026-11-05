<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiBibleBudgetExhaustedException;
use App\Models\Sermon;
use App\Services\Scripture\ScriptureOperatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FetchBibleTextForSermon implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private Sermon $sermon,
    ) {}

    public function handle(
        ScriptureOperatorService $scriptureOperatorService,
    ): void {
        if (! config('services.api_bible.enabled')) {
            return;
        }

        // A passage the provider returned but that could not be stored is a failure the queue must
        // see and retry: on 11-02, 33 refreshes discarded their passages and returned normally.
        // Not found and unparseable references are terminal outcomes, not failures.
        if ($scriptureOperatorService->enrichSermon($this->sermon) === 'failed') {
            throw new RuntimeException("Scripture passage for sermon {$this->sermon->id} could not be stored.");
        }
    }

    /**
     * Budget exhaustion is non-retryable — the daily limit resets at midnight,
     * not in 30 seconds. FailOnException marks the job as permanently failed
     * immediately, bypassing the retry/backoff cycle.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new FailOnException([ApiBibleBudgetExhaustedException::class])];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FetchBibleTextForSermon: job failed permanently', [
            'sermon_id' => $this->sermon->id,
            'reference' => $this->sermon->reference,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }
}

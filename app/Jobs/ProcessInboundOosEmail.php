<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InboundEmailStatus;
use App\Models\ImportDeferredInboundEmail;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use App\Services\Import\ImportIngressGate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessInboundOosEmail implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The database lease HIR6 puts on a deferred row is derived from this, so
     * the two cannot drift apart: a lease that expired first would let a drain
     * reclaim a row whose job is still queued.
     *
     * @see ImportIngressGate::leaseSeconds()
     */
    public const int UniqueForSeconds = 86_400;

    public int $tries = 3;

    public int $uniqueFor = self::UniqueForSeconds;

    public function __construct(
        private InboundEmail $inboundEmail,
        private readonly ?int $deferredInboundEmailId = null,
    ) {}

    public function handle(
        OosEmailParserService $parser,
        InboundEmailImportService $importService,
    ): void {
        $deferred = $this->lockedDeferredInboundEmail();

        /**
         * Already finished. A redelivered webhook, a repeated drain and a
         * retried job all reach here, and none of them may import a second
         * time.
         */
        if ($this->deferredInboundEmailId !== null
            && ($deferred === null || $deferred->processed_at !== null)) {
            return;
        }

        $inboundEmail = $this->inboundEmail->fresh();
        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $parseResult = $parser->parse($inboundEmail);
        $importService->storeParseResult($inboundEmail, $parseResult);

        $autoImportablePlans = array_filter(
            $parseResult->servicePlans,
            static fn ($plan): bool => $plan->isAutoImportable(),
        );

        if ($autoImportablePlans === []) {
            $inboundEmail->refresh();
            $inboundEmail->status = InboundEmailStatus::Pending;
            $inboundEmail->save();
            $this->markDeferredProcessed();

            return;
        }

        // Imports every confident plan and holds the rest. Held plans (below the auto-import bar)
        // are a normal outcome that leaves the email Pending in the inbox with its confident orders
        // already imported and per-plan state recorded.
        $result = $importService->import($inboundEmail, $parseResult);

        // A plan that *failed* to import (a DB or sync error) is not a hold — swallowing it would
        // rob the queue of its retry/failed path and leave ops with no signal. The per-plan
        // outcomes are already recorded, so re-raising here retries the whole job (create-only /
        // merge make the already-imported plans idempotent) and surfaces a persistent failure.
        if ($result->hasFailures()) {
            throw new RuntimeException(sprintf(
                'Inbound OoS email %d had %d service plan(s) fail to import.',
                $inboundEmail->id,
                count($result->failed()),
            ));
        }

        if (! $result->isFullyResolved()) {
            $inboundEmail->refresh();

            if ($inboundEmail->status !== InboundEmailStatus::Processed) {
                $inboundEmail->status = InboundEmailStatus::Pending;
                $inboundEmail->save();
            }
        }

        $this->markDeferredProcessed();
    }

    public function uniqueId(): string
    {
        return $this->deferredInboundEmailId === null
            ? (string) $this->inboundEmail->getKey()
            : "deferred:{$this->deferredInboundEmailId}";
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['inbound_email:'.$this->inboundEmail->getKey()];
    }

    public function failed(\Throwable $exception): void
    {
        $inboundEmail = $this->inboundEmail->fresh();

        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $inboundEmail->status = InboundEmailStatus::Failed;
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'failure' => [
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'attempt' => $this->attempts(),
                    'queue_name' => $this->job?->getQueue(),
                    'failed_at' => now()->toIso8601String(),
                ],
            ],
        );
        $inboundEmail->save();

        /**
         * The claim goes back to the drain, with the reason attached. A row that
         * already reached `processed` is left alone: a later attempt failing
         * after a successful one must not reopen a finished import.
         */
        if ($this->deferredInboundEmailId !== null) {
            ImportDeferredInboundEmail::query()
                ->whereKey($this->deferredInboundEmailId)
                ->whereNull('processed_at')
                ->update([
                    'state' => ImportDeferredInboundEmail::StatePending,
                    'dispatch_token' => null,
                    'dispatch_claimed_at' => null,
                    'lease_expires_at' => null,
                    'dispatched_at' => null,
                    'last_failed_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 480),
                    'failure_count' => DB::raw('failure_count + 1'),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array<string, mixed>  $newMetadata
     * @return array<string, mixed>
     */
    private function mergeProcessingMetadata(?array $existingMetadata, array $newMetadata): array
    {
        $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];

        return array_replace_recursive($existingMetadata, $newMetadata);
    }

    /**
     * Reload the outbox row under a lock, so two workers that both reached this
     * job cannot both read it as unfinished. The lock is held for the read
     * alone — the parse and import that follow are far too long to hold a row
     * lock across.
     */
    private function lockedDeferredInboundEmail(): ?ImportDeferredInboundEmail
    {
        if ($this->deferredInboundEmailId === null) {
            return null;
        }

        return DB::transaction(fn (): ?ImportDeferredInboundEmail => ImportDeferredInboundEmail::query()
            ->whereKey($this->deferredInboundEmailId)
            ->lockForUpdate()
            ->first());
    }

    /**
     * The only terminal transition. Conditional on the row not already being
     * processed, so a retry that races a successful attempt cannot move
     * `processed_at`.
     */
    private function markDeferredProcessed(): void
    {
        if ($this->deferredInboundEmailId === null) {
            return;
        }

        ImportDeferredInboundEmail::query()
            ->whereKey($this->deferredInboundEmailId)
            ->whereNull('processed_at')
            ->update([
                'state' => ImportDeferredInboundEmail::StateProcessed,
                'processed_at' => now(),
                'dispatch_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }
}

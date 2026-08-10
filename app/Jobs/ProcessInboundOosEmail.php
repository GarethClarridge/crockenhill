<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InboundEmailStatus;
use App\Models\ImportDeferredInboundEmail;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ProcessInboundOosEmail implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86_400;

    public function __construct(
        private InboundEmail $inboundEmail,
        private readonly ?int $deferredInboundEmailId = null,
    ) {}

    public function handle(
        OosEmailParserService $parser,
        InboundEmailImportService $importService,
    ): void {
        $deferred = $this->deferredInboundEmail();

        if ($deferred?->processed_at !== null) {
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
            $this->markDeferredProcessed($deferred);

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

        $this->markDeferredProcessed($deferred);
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

        if ($this->deferredInboundEmailId !== null) {
            ImportDeferredInboundEmail::query()
                ->whereKey($this->deferredInboundEmailId)
                ->whereNull('processed_at')
                ->update(['state' => 'pending', 'dispatched_at' => null]);
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

    private function deferredInboundEmail(): ?ImportDeferredInboundEmail
    {
        if ($this->deferredInboundEmailId === null) {
            return null;
        }

        return ImportDeferredInboundEmail::query()->find($this->deferredInboundEmailId);
    }

    private function markDeferredProcessed(?ImportDeferredInboundEmail $deferred): void
    {
        if (! $deferred instanceof ImportDeferredInboundEmail) {
            return;
        }

        $deferred->forceFill([
            'state' => 'processed',
            'processed_at' => now(),
        ])->save();
    }
}

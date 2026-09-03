<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HistoricStagingContextRegistry
{
    /** Where an activate/deactivate pair came from, for the depth instrumentation. */
    public const SOURCE_QUEUE_BEFORE = 'queue_before';

    public const SOURCE_QUEUE_AFTER = 'queue_after';

    public const SOURCE_QUEUE_EXCEPTION = 'queue_exception';

    public const SOURCE_WITHIN = 'within';


    private ?HistoricStagingContext $context = null;

    private ?HistoricStagingActivation $activation = null;

    /**
     * Activations nest. A job dispatched inside an active batch may execute
     * inline — the sync connection does exactly this — and its own
     * activate/deactivate pair must not release the batch surrounding it. Only
     * the caller that opened the context closes it.
     */
    private int $depth = 0;

    public function __construct(
        private readonly HistoricStagingGuard $stagingGuard,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function within(HistoricStagingContext $context, Closure $callback): mixed
    {
        $this->depth++;
        $this->recordDepthTransition('activate', self::SOURCE_WITHIN);

        try {
            $this->enter($context);

            return $callback();
        } finally {
            $this->deactivate(self::SOURCE_WITHIN);
        }
    }

    /** @return array<string, mixed> */
    public function queuePayload(): array
    {
        if (! $this->context instanceof HistoricStagingContext) {
            return [];
        }

        return ['historic_staging_context' => $this->context->toArray()];
    }

    /**
     * A job carrying no context still balances its `Queue::before` against its
     * `Queue::after`, so it counts as a level of nesting like any other. The
     * depth is raised before anything can throw, so every increment has exactly
     * one matching decrement.
     *
     * @param  array<string, mixed>  $payload
     */
    public function activateQueuePayload(array $payload, ?string $jobName = null): void
    {
        $context = $payload['historic_staging_context'] ?? null;
        $this->depth++;
        $this->recordDepthTransition('activate', self::SOURCE_QUEUE_BEFORE, $jobName);

        if (! is_array($context)) {
            return;
        }

        $this->enter(HistoricStagingContext::fromArray($context));
    }

    public function isActive(): bool
    {
        return $this->context instanceof HistoricStagingContext;
    }

    public function activeContext(): ?HistoricStagingContext
    {
        return $this->context;
    }

    public function deactivate(?string $source = null, ?string $jobName = null): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }

        if ($this->depth > 0) {
            $this->recordDepthTransition('deactivate', $source ?? self::SOURCE_QUEUE_AFTER, $jobName);

            return;
        }

        $this->activation?->restore();
        $this->activation = null;
        $this->context = null;

        $this->recordDepthTransition('deactivate', $source ?? self::SOURCE_QUEUE_AFTER, $jobName);
    }

    /**
     * Name the path any future staging leak takes.
     *
     * D7 (2026-09-03) decided to instrument this rather than chase it: the
     * guard's static baseline already absorbs the leak, so nothing is stranded,
     * but activate/deactivate went out of balance six times in three runs in ten
     * minutes and no evidence said where. Every transition therefore records the
     * depth it moved to, which of the four call sites moved it, and the staging
     * disk root as configuration currently reports it — the value a leaked
     * activation corrupts. A root diverging from the pristine baseline while
     * nothing should be active *is* the leak, so that case is a warning carrying
     * both paths; the balanced transitions stay at debug.
     *
     * Escalate to a real repro only if a run's durable output ever lands under a
     * batch root it should not be under.
     */
    private function recordDepthTransition(string $event, string $source, ?string $jobName = null): void
    {
        try {
            $disk = $this->stagingGuard->stagingDisk();
            $leak = $this->depth === 0 ? $this->stagingGuard->leakedActivationEvidence($disk) : null;

            $entry = [
                'event' => $event,
                'source' => $source,
                'job' => $jobName,
                'depth' => $this->depth,
                'context_active' => $this->context instanceof HistoricStagingContext,
                'batch_root' => $this->context?->batchRoot,
                'staging_disk' => $disk,
                'staging_disk_root' => $this->stagingGuard->liveRoot($disk),
            ];

            if ($leak === null) {
                Log::debug('Historic staging depth transition', $entry);

                return;
            }

            Log::warning('Historic staging activation leaked: the staging root is still batch-rooted at depth zero', $entry + $leak);
        } catch (Throwable $exception) {
            // Instrumentation must never be the reason a job fails. An
            // unconfigured staging disk is already reported by the guard itself
            // at the point it matters.
            Log::debug('Historic staging depth transition could not be recorded', [
                'event' => $event,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function enter(HistoricStagingContext $context): void
    {
        if ($this->context instanceof HistoricStagingContext) {
            if ($this->context->toArray() !== $context->toArray()) {
                throw new RuntimeException('A worker cannot process two historic staging batches at once.');
            }

            return;
        }

        $this->activation = $this->stagingGuard->activate($context);
        $this->context = $context;
    }
}

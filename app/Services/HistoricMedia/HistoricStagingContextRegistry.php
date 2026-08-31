<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use Closure;
use RuntimeException;

class HistoricStagingContextRegistry
{
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

        try {
            $this->enter($context);

            return $callback();
        } finally {
            $this->deactivate();
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
    public function activateQueuePayload(array $payload): void
    {
        $context = $payload['historic_staging_context'] ?? null;
        $this->depth++;

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

    public function deactivate(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }

        if ($this->depth > 0) {
            return;
        }

        $this->activation?->restore();
        $this->activation = null;
        $this->context = null;
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

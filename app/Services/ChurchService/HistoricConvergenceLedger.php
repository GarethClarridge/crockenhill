<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\HistoricConvergenceOperationPlan;
use RuntimeException;

/**
 * Private append-only evidence for a historic convergence operation.
 *
 * The ledger deliberately stores the operation's portable summary only. Local
 * primary keys, absolute paths, raw source content and reviewer identities do
 * not belong in an operational record that may be retained or copied for
 * closeout evidence.
 */
class HistoricConvergenceLedger
{
    public function __construct(private readonly ?string $path = null) {}

    /** @param array<string, mixed> $entry */
    public function append(array $entry): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The historic convergence ledger directory could not be created.');
        }

        @chmod($directory, 0700);
        $handle = fopen($path, 'ab');

        if ($handle === false) {
            throw new RuntimeException('The historic convergence ledger could not be opened.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The historic convergence ledger could not be locked.');
            }

            $line = json_encode($this->sanitize($entry), JSON_THROW_ON_ERROR).PHP_EOL;

            if (fwrite($handle, $line) === false) {
                throw new RuntimeException('The historic convergence ledger could not be appended.');
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            @chmod($path, 0600);
        }
    }

    public function recordPrepared(HistoricConvergenceOperationPlan $plan): void
    {
        $this->append([
            'event' => 'prepared',
            'operation_id' => $plan->operationId,
            'plan_hash' => $plan->planHash,
            'content_hash' => $plan->contentHash,
            'batch_hash' => $plan->batchHash,
            'media_bundle_hash' => $plan->mediaBundleHash,
            'convergence_bundle_hash' => $plan->convergenceBundleHash,
            'expires_at' => $plan->expiresAt->format(DATE_ATOM),
            'summary' => $plan->summary,
        ]);
    }

    /** @param array<string, mixed> $service */
    public function recordStarted(HistoricConvergenceOperationPlan $plan, array $service): void
    {
        $this->append([
            'event' => 'service_started',
            'operation_id' => $plan->operationId,
            'plan_hash' => $plan->planHash,
            'content_hash' => $plan->contentHash,
            'identity' => $service['identity'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $service */
    public function recordCompleted(HistoricConvergenceOperationPlan $plan, array $service): void
    {
        $this->append([
            'event' => 'service_completed',
            'operation_id' => $plan->operationId,
            'plan_hash' => $plan->planHash,
            'content_hash' => $plan->contentHash,
            'identity' => $service['identity'] ?? null,
            'classification' => $service['summary']['convergence_classification'] ?? null,
        ]);
    }

    /**
     * The failure text is reduced to a hash because it is the one field an
     * operation cannot bound — it may quote a local key, a resolved path or a
     * source fragment. The named `phase` carries the explanation instead: it is
     * a fixed token chosen by the caller, so a retained ledger says exactly how
     * far the service got without repeating anything the operation read.
     */
    public function recordFailed(
        HistoricConvergenceOperationPlan $plan,
        string $phase,
        string $reason,
        ?string $identity = null,
    ): void {
        $this->append([
            'event' => 'failed',
            'operation_id' => $plan->operationId,
            'plan_hash' => $plan->planHash,
            'content_hash' => $plan->contentHash,
            'identity' => $identity,
            'phase' => $phase,
            'reason_hash' => hash('sha256', $reason),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(?string $operationId = null): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $entries = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException('The historic convergence ledger could not be read.');
        }

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                throw new RuntimeException('The historic convergence ledger contains invalid JSON.');
            }

            if ($operationId === null || $entry['operation_id'] === $operationId) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Completion is keyed on the operation identity and the service, and on
     * nothing that applying the service changes.
     *
     * Neither hash works here. The approval token binds the expiry, and a resume
     * necessarily re-runs the dry run, so the token always differs. The content
     * hash binds each service's classification, which is exactly what a
     * successful apply flips from `create` to `already_present` — so a ledger
     * entry written at completion could never match the plan that reads it back.
     * Either choice would silently re-apply every finished service.
     *
     * The operation identifier is derived from the bundle pair and the service
     * identities it covers, so it pins "the same operation over the same corpus"
     * without tracking production state. That production still holds the applied
     * result is a separate question, answered by revalidating the skipped
     * services against a fresh preflight rather than by trusting this record.
     */
    public function hasCompleted(string $operationId, string $identity): bool
    {
        foreach ($this->entries($operationId) as $entry) {
            if (
                $entry['event'] === 'service_completed'
                && $entry['identity'] === $identity
            ) {
                return true;
            }
        }

        return false;
    }

    private function path(): string
    {
        return $this->path ?? storage_path('app/private/historic-convergence/ledger.jsonl');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sanitize(array $value): array
    {
        $sanitized = [];

        foreach ($value as $key => $nested) {
            if (
                $key !== 'operation_id'
                && preg_match('/(^|_)(id|ids)$/i', (string) $key) === 1
            ) {
                throw new RuntimeException('The historic convergence ledger cannot contain database identities.');
            }

            if (preg_match('/(secret|token|password|body|path|root)/i', (string) $key) === 1) {
                throw new RuntimeException("The historic convergence ledger cannot contain {$key}.");
            }

            $sanitized[$key] = is_array($nested) ? $this->sanitize($nested) : $nested;
        }

        return $sanitized;
    }
}

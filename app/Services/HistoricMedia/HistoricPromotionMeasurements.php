<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

/**
 * Extracts §13.4's promotion timings from a convergence ledger.
 *
 * The ledger is the operation's own append-only record, so it is the only
 * source that cannot disagree with what the operation actually did. Nothing is
 * inferred: an event that carries no duration contributes no sample, and the
 * count of samples travels with them so a budget built on two observations
 * cannot be mistaken for one built on two hundred.
 *
 * @phpstan-type Samples array{
 *     apply_seconds: list<float>,
 *     preflight_seconds: list<float>,
 *     closeout_seconds: list<float>,
 *     rollback_seconds: list<float>,
 *     asset_bytes: list<int>,
 *     asset_seconds: list<float>,
 *     operations: list<string>,
 *     services_applied: int,
 *     services_failed: int,
 * }
 */
final class HistoricPromotionMeasurements
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @return Samples
     */
    public function fromLedgerEntries(array $entries): array
    {
        $samples = [
            'apply_seconds' => [],
            'preflight_seconds' => [],
            'closeout_seconds' => [],
            'rollback_seconds' => [],
            'asset_bytes' => [],
            'asset_seconds' => [],
            'operations' => [],
            'services_applied' => 0,
            'services_failed' => 0,
        ];

        foreach ($entries as $entry) {
            $operationId = $entry['operation_id'] ?? null;

            if (is_string($operationId) && ! in_array($operationId, $samples['operations'], true)) {
                $samples['operations'][] = $operationId;
            }

            $duration = $this->float($entry['duration_seconds'] ?? null);

            match ($entry['event'] ?? null) {
                'prepared' => $this->push($samples['preflight_seconds'], $duration),
                'closeout' => $this->push($samples['closeout_seconds'], $duration),
                'service_completed' => $this->recordCompletion($samples, $entry, $duration),
                // A failed service is where the rollback reserve is observed: the
                // recorded duration spans the attempt and its asset compensation.
                'failed' => $this->recordFailure($samples, $duration),
                default => null,
            };
        }

        return $samples;
    }

    /**
     * @param  Samples  $samples
     * @param  array<string, mixed>  $entry
     */
    private function recordCompletion(array &$samples, array $entry, ?float $duration): void
    {
        $samples['services_applied']++;
        $this->push($samples['apply_seconds'], $duration);

        $bytes = $entry['asset_bytes'] ?? null;
        $assetSeconds = $this->float($entry['asset_seconds'] ?? null);

        // Both halves of a throughput sample or neither: bytes with no elapsed
        // time, or time with no bytes, would each skew the ratio on its own.
        if (is_int($bytes) && $bytes > 0 && $assetSeconds !== null && $assetSeconds > 0.0) {
            $samples['asset_bytes'][] = $bytes;
            $samples['asset_seconds'][] = $assetSeconds;
        }
    }

    /** @param Samples $samples */
    private function recordFailure(array &$samples, ?float $duration): void
    {
        $samples['services_failed']++;
        $this->push($samples['rollback_seconds'], $duration);
    }

    /** @param list<float> $target */
    private function push(array &$target, ?float $duration): void
    {
        if ($duration !== null) {
            $target[] = $duration;
        }
    }

    private function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Support\CanonicalJson;
use App\Support\OpenAiChatPayload;
use App\Support\RepositoryCommit;
use RuntimeException;
use Throwable;

/**
 * Runs the report-only sermon-analysis arm over immutable banked transcript files.
 *
 * This is deliberately bounded to one sequential pass. It does not create processing logs or
 * sermons; the synthetic processing id exists only to satisfy the existing analysis contract and
 * to bind the provider's usage log to one banked input.
 *
 * Delete this one-shot evaluation surface at historic-import IC8 closeout.
 */
class SermonAnalysisEvaluationRunner
{
    private const Format = 'crockenhill-sermon-analysis-evaluation';

    private const Version = 1;

    public function __construct(
        private readonly SermonAnalysisInterface $analysisService,
        private readonly SermonAnalysisEvaluationTelemetry $telemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $priceSnapshot
     * @return array<string, mixed>
     */
    public function run(
        string $manifestPath,
        string $arm,
        array $priceSnapshot,
        int $delaySeconds = 0,
    ): array {
        $manifestContents = file_get_contents($manifestPath);

        if (! is_string($manifestContents)) {
            throw new RuntimeException("Unable to read sermon-analysis manifest at {$manifestPath}.");
        }

        $manifest = $this->decodeManifest($manifestContents);
        $entries = $this->loadEntries($manifest, $manifestPath);
        $manifestHash = hash('sha256', $manifestContents);
        $corpusHash = CanonicalJson::hash(array_map(
            static fn (array $entry): array => [
                'label' => $entry['label'],
                'input_sha256' => $entry['input_sha256'],
            ],
            $entries,
        ));

        $model = (string) config('media-processing.analysis.model', 'gpt-5.6-terra');
        $configuredReasoningEffort = (string) config('media-processing.analysis.reasoning_effort', 'low');
        $this->assertPriceSnapshot($priceSnapshot, $model);

        $results = [];
        $runStartedAt = microtime(true);

        foreach ($entries as $offset => $entry) {
            /**
             * Pacing, not a second transport path.
             *
             * Six back-to-back calls trip the provider's request rate limit, which fails five of
             * six arms and makes the report worthless. Sleeping between calls keeps them serial —
             * which IC5 item 6b requires for deterministic attribution — without touching the
             * client's own timeout and retry behaviour, which stays authoritative.
             */
            if ($offset > 0 && $delaySeconds > 0) {
                sleep($delaySeconds);
            }

            $this->telemetry->begin($entry['label'], $entry['input_sha256']);
            $processingId = $entry['processing_id'];
            $startedAt = microtime(true);
            $exception = null;
            $analysis = null;

            try {
                $analysis = $this->analysisService->analyzeSermon(
                    $entry['transcript'],
                    processingId: $processingId,
                );
            } catch (Throwable $throwable) {
                $exception = $throwable;
                $this->telemetry->recordFailure($throwable);
            }

            $capture = $this->telemetry->finish();
            $results[] = $this->result($entry, $capture, $analysis, $exception, microtime(true) - $startedAt);
        }

        return [
            'format' => self::Format,
            'version' => self::Version,
            'generated_at' => now()->toIso8601String(),
            'arm' => $arm,
            'application_commit' => RepositoryCommit::current(),
            'manifest_sha256' => $manifestHash,
            'corpus_hash' => $corpusHash,
            'transcript_count' => count($entries),
            'requested_model' => $model,
            'configured_reasoning_effort' => $configuredReasoningEffort,
            'effective_reasoning_effort' => OpenAiChatPayload::effectiveReasoningEffort($model, $configuredReasoningEffort),
            'service_tier' => config('openai.service_tier'),
            'retries' => 0,
            'rechecks' => 0,
            'price_snapshot' => [
                'taken_at' => $priceSnapshot['taken_at'] ?? $priceSnapshot['snapshot_date'] ?? null,
                'sha256' => CanonicalJson::hash($priceSnapshot),
            ],
            'summary' => $this->summary($results, microtime(true) - $runStartedAt, $priceSnapshot),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeManifest(string $contents): array
    {
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('The sermon-analysis manifest is not valid JSON.', previous: $throwable);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException('The sermon-analysis manifest must contain a JSON object.');
        }

        if (($manifest['format'] ?? null) !== self::Format || ($manifest['version'] ?? null) !== self::Version) {
            throw new RuntimeException(sprintf(
                'Unsupported sermon-analysis manifest; expected %s version %d.',
                self::Format,
                self::Version,
            ));
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{label:string,processing_id:string,transcript:string,input_sha256:string,relative_path:string}>
     */
    private function loadEntries(array $manifest, string $manifestPath): array
    {
        $rawEntries = $manifest['transcripts'] ?? $manifest['services'] ?? null;

        if (! is_array($rawEntries) || $rawEntries === []) {
            throw new RuntimeException('The sermon-analysis manifest must contain at least one transcript.');
        }

        $entries = [];
        $labels = [];
        $processingIds = [];
        $manifestDirectory = dirname($manifestPath);

        foreach ($rawEntries as $rawEntry) {
            if (! is_array($rawEntry)) {
                throw new RuntimeException('Every sermon-analysis manifest transcript must be an object.');
            }

            $label = $rawEntry['label'] ?? null;
            $file = $rawEntry['transcript_file'] ?? $rawEntry['file'] ?? null;

            if (! is_string($label) || trim($label) === '') {
                throw new RuntimeException('Every sermon-analysis transcript requires a non-empty label.');
            }

            if (! is_string($file) || trim($file) === '') {
                throw new RuntimeException("Sermon-analysis input {$label} has no transcript_file.");
            }

            $label = trim($label);
            $path = str_starts_with($file, '/') ? $file : "{$manifestDirectory}/{$file}";
            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new RuntimeException("Unable to read sermon-analysis transcript {$file} for {$label}.");
            }

            $inputHash = hash('sha256', $contents);
            $declaredHash = $rawEntry['sha256'] ?? $rawEntry['transcript_sha256'] ?? null;

            if ($declaredHash !== null
                && (! is_string($declaredHash) || ! hash_equals($inputHash, $declaredHash))) {
                throw new RuntimeException("Transcript hash mismatch for {$label}; the banked input changed.");
            }

            $processingId = $rawEntry['processing_id'] ?? null;
            if (! is_string($processingId) || trim($processingId) === '') {
                $processingId = 'sermon-analysis-evaluation-'.str()->slug($label);
            }

            if (in_array($label, $labels, true)) {
                throw new RuntimeException("Duplicate sermon-analysis transcript label: {$label}.");
            }

            if (in_array($processingId, $processingIds, true)) {
                throw new RuntimeException("Duplicate sermon-analysis processing id: {$processingId}.");
            }

            $labels[] = $label;
            $processingIds[] = $processingId;
            $entries[] = [
                'label' => $label,
                'processing_id' => $processingId,
                'transcript' => $contents,
                'input_sha256' => $inputHash,
                'relative_path' => $file,
            ];
        }

        return $entries;
    }

    /**
     * @param  array{label:string,processing_id:string,transcript:string,input_sha256:string,relative_path:string}  $entry
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    private function result(
        array $entry,
        array $capture,
        ?SermonAnalysis $analysis,
        ?Throwable $exception,
        float $wallTimeSeconds,
    ): array {
        $failure = $capture['failure'] ?? null;

        if ($exception !== null && ! is_array($failure)) {
            $failure = [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }

        $response = is_array($capture['response'] ?? null) ? $capture['response'] : null;
        $validation = is_array($capture['validation'] ?? null) ? $capture['validation'] : null;
        $truncated = ($response['truncated'] ?? false) === true;

        if ($truncated && $failure === null) {
            $failure = [
                'exception' => RuntimeException::class,
                'message' => 'The model response was truncated before completion.',
            ];
        }

        return [
            'label' => $entry['label'],
            'processing_id' => $entry['processing_id'],
            'input_sha256' => $entry['input_sha256'],
            'transcript_path' => $entry['relative_path'],
            'status' => $exception === null && ! $truncated ? 'succeeded' : 'failed',
            'retries' => 0,
            'rechecks' => 0,
            'wall_time_ms' => (int) round($wallTimeSeconds * 1000),
            'request' => $capture['request'] ?? null,
            'response' => $response,
            'validation' => $validation,
            'failure' => $failure,
            'normalised_output' => $analysis === null ? null : $this->normalisedOutput($analysis),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalisedOutput(SermonAnalysis $analysis): array
    {
        $output = $analysis->toArray();
        unset($output['transcript']);

        return $output;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $priceSnapshot
     * @return array<string, mixed>
     */
    private function summary(array $results, float $wallTimeSeconds, array $priceSnapshot): array
    {
        $latencies = array_map(
            static fn (array $result): int => (int) $result['wall_time_ms'],
            $results,
        );
        sort($latencies);

        $usage = [
            'calls' => 0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 0,
        ];
        $truncated = 0;
        $usageMissing = 0;

        foreach ($results as $result) {
            $response = is_array($result['response'] ?? null) ? $result['response'] : null;

            if ($response === null) {
                continue;
            }

            if (($response['truncated'] ?? false) === true) {
                $truncated++;
            }

            if (($response['usage_missing'] ?? true) === true || ! is_array($response['usage'] ?? null)) {
                $usageMissing++;

                continue;
            }

            $usage['calls']++;
            foreach (array_keys($usage) as $key) {
                if ($key !== 'calls') {
                    $usage[$key] += (int) ($response['usage'][$key] ?? 0);
                }
            }
        }

        $successfulCount = count(array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'succeeded',
        ));
        $firstAttemptCost = $this->cost($results, $priceSnapshot);
        $callsPerIdentity = count($results) === 0 ? 0.0 : $usage['calls'] / count($results);

        return [
            'success_count' => $successfulCount,
            'failure_count' => count($results) - $successfulCount,
            'usage_missing_count' => $usageMissing,
            'truncated_count' => $truncated,
            'wall_time_ms' => (int) round($wallTimeSeconds * 1000),
            'wall_time_p50_ms' => $this->percentile($latencies, 0.50),
            'wall_time_p95_ms' => $this->percentile($latencies, 0.95),
            'usage' => $usage,
            'cost' => [
                'first_attempt_usd' => round($firstAttemptCost, 8),
                'observed_calls_per_identity' => round($callsPerIdentity, 8),
                'projected_470_identity_usd' => round(
                    count($results) === 0 ? 0.0 : ($firstAttemptCost / count($results)) * 470 * $callsPerIdentity,
                    6,
                ),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $priceSnapshot
     */
    private function cost(array $results, array $priceSnapshot): float
    {
        $model = (string) config('media-processing.analysis.model', 'gpt-5.6-terra');
        $prices = $priceSnapshot['models'][$model] ?? [];
        $inputPrice = (float) ($prices['input'] ?? 0);
        $cachedInputPrice = (float) ($prices['cached_input'] ?? $inputPrice);
        $outputPrice = (float) ($prices['output'] ?? 0);
        $total = 0.0;

        foreach ($results as $result) {
            $response = is_array($result['response'] ?? null) ? $result['response'] : null;
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : null;

            if ($usage === null) {
                continue;
            }

            $inputTokens = (int) ($usage['input_tokens'] ?? 0);
            $cachedInputTokens = min($inputTokens, (int) ($usage['cached_input_tokens'] ?? 0));
            $outputTokens = (int) ($usage['output_tokens'] ?? 0);
            $total += (
                (($inputTokens - $cachedInputTokens) * $inputPrice)
                + ($cachedInputTokens * $cachedInputPrice)
                + ($outputTokens * $outputPrice)
            ) / 1_000_000;
        }

        return $total;
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $fraction): ?int
    {
        if ($values === []) {
            return null;
        }

        $position = ($fraction * (count($values) - 1));
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $values[$lower];
        }

        return (int) round($values[$lower] + (($values[$upper] - $values[$lower]) * ($position - $lower)));
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPriceSnapshot(array $snapshot, string $model): void
    {
        $prices = $snapshot['models'][$model] ?? null;

        if (! is_array($prices)
            || ! is_numeric($prices['input'] ?? null)
            || ! is_numeric($prices['output'] ?? null)
            || ! is_string($snapshot['taken_at'] ?? $snapshot['snapshot_date'] ?? null)) {
            throw new RuntimeException("Price snapshot does not bind {$model} input/output prices and a date.");
        }
    }
}

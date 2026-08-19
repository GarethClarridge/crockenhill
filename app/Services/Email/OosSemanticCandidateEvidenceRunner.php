<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Support\CanonicalJson;
use App\Support\RepositoryCommit;
use RuntimeException;

/**
 * Executes the scoreable private corpus once and retains every candidate attempt.
 *
 * Correctness scoring is deliberately separate: this runner produces evidence and may not label a
 * candidate passing merely because its responses compiled.
 */
class OosSemanticCandidateEvidenceRunner
{
    public const string Format = 'crockenhill-oos-semantic-candidate-evidence';

    public const int Version = 1;

    public function __construct(
        private readonly OosSemanticEvaluationCorpusGate $gate,
        private readonly OosSemanticParserCandidate $candidate,
        private readonly OosParserSurfaceFingerprint $fingerprint,
        private readonly OosSemanticAnnotationPrompt $prompt,
    ) {}

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $priceSnapshot
     * @return array<string, mixed>
     */
    public function run(array $corpus, array $priceSnapshot): array
    {
        $this->gate->assertScoreable($corpus);
        $model = (string) config('service-tracking.email_parsing.semantic.model');
        $effort = (string) config('service-tracking.email_parsing.semantic.reasoning_effort');
        $this->assertPriceSnapshot($priceSnapshot, $model);
        $results = [];
        $calls = [];

        foreach ($corpus['sources'] as $sourceRecord) {
            $source = $this->sourceDocument($sourceRecord);
            $outcome = $this->candidate->parse($source);
            $attempt = $outcome->attempts[0];
            $calls[] = $this->callTelemetry($sourceRecord['item_key'], 'annotation', $attempt['initial_annotations']['telemetry'] ?? null);

            if (is_array($attempt['repair_telemetry'] ?? null) && $attempt['repair_telemetry'] !== []) {
                $calls[] = $this->callTelemetry($sourceRecord['item_key'], 'repair', $attempt['repair_telemetry']);
            }

            $results[] = [
                'item_key' => $sourceRecord['item_key'],
                'source_hash' => $source->inputHash(),
                'extraction' => $this->extraction($outcome->extraction),
                'risk_signals' => $outcome->riskSignals,
                'attempts' => $outcome->attempts,
            ];
        }

        $returnedModels = array_values(array_unique(array_column($calls, 'returned_model')));

        if (count($returnedModels) !== 1) {
            throw new RuntimeException('Provider did not return one stable model identifier across the candidate run.');
        }

        $artifact = [
            'format' => self::Format,
            'version' => self::Version,
            'candidate' => [
                'configured_model' => $model,
                'returned_model' => $returnedModels[0],
                'reasoning_effort' => $effort,
                'service_tier' => config('openai.service_tier'),
                'prompt_version' => OosSemanticAnnotationPrompt::Version,
                'prompt_sha256' => hash('sha256', $this->prompt->text()),
                'parser_surface' => $this->fingerprint->fingerprint(),
            ],
            'inputs' => [
                'corpus_hash' => $corpus['corpus_hash'],
                'price_snapshot_sha256' => CanonicalJson::hash($priceSnapshot),
                'price_snapshot' => $priceSnapshot,
                'application_commit' => RepositoryCommit::current(),
            ],
            'usage' => $this->usage($calls),
            'calls' => $calls,
            'results' => $results,
        ];
        $artifact['evidence_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    /** @param array<string, mixed> $record */
    private function sourceDocument(array $record): OosEmailSourceDocument
    {
        $portable = $record['source_document'];
        $physical = [];

        foreach ($portable['lines'] as $line) {
            $physical[(int) $line['physical_position']] = (string) $line['exact_text'];
        }

        if ($physical === []) {
            throw new RuntimeException("Semantic source {$record['item_key']} contains no physical lines.");
        }

        $last = max(array_keys($physical));
        $body = [];

        for ($position = 1; $position <= $last; $position++) {
            $body[] = $physical[$position] ?? '';
        }

        $source = OosEmailSourceDocument::fromContext(
            is_string($portable['subject'] ?? null) ? $portable['subject'] : null,
            implode("\n", $body),
            is_string($portable['received_date'] ?? null) ? $portable['received_date'] : null,
        );

        if (! hash_equals($portable['input_hash'], $source->inputHash())) {
            throw new RuntimeException("Reconstructed semantic source {$record['item_key']} changed hash.");
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function extraction(OosEmailItemExtractionResult $extraction): array
    {
        return [
            'items' => $extraction->items,
            'confidence' => $extraction->confidence,
            'notes' => $extraction->notes,
            'services' => $extraction->services,
            'service_count' => $extraction->serviceCount,
            'ignored_lines' => $extraction->ignoredLines,
            'provenance_complete' => $extraction->provenanceComplete,
        ];
    }

    /** @return array<string, mixed> */
    private function callTelemetry(string $itemKey, string $role, mixed $telemetry): array
    {
        if (! is_array($telemetry)
            || ! is_string($telemetry['returned_model'] ?? null)
            || ! is_int($telemetry['latency_ms'] ?? null)
            || ! is_array($telemetry['usage'] ?? null)) {
            throw new RuntimeException("Candidate {$role} call for {$itemKey} returned incomplete telemetry.");
        }

        return ['item_key' => $itemKey, 'role' => $role] + $telemetry;
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array{calls:int,input_tokens:int,output_tokens:int,total_tokens:int,latency_ms:int}
     */
    private function usage(array $calls): array
    {
        $totals = ['calls' => count($calls), 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'latency_ms' => 0];

        foreach ($calls as $call) {
            $totals['input_tokens'] += (int) $call['usage']['input_tokens'];
            $totals['output_tokens'] += (int) $call['usage']['output_tokens'];
            $totals['total_tokens'] += (int) $call['usage']['total_tokens'];
            $totals['latency_ms'] += (int) $call['latency_ms'];
        }

        return $totals;
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPriceSnapshot(array $snapshot, string $model): void
    {
        $prices = $snapshot['models'][$model] ?? null;

        if (! is_array($prices)
            || ! is_numeric($prices['input'] ?? null)
            || ! is_numeric($prices['output'] ?? null)
            || ! is_string($snapshot['taken_at'] ?? null)
            || ! is_string($snapshot['billing_mode'] ?? null)) {
            throw new RuntimeException("Price snapshot does not bind {$model} input/output prices and billing mode.");
        }
    }
}

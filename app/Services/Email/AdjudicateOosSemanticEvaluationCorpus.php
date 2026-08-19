<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticLineAnnotation;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Builds the private -adjudicated semantic evaluation corpus from maintainer decisions over the
 * frozen -prefilled worksheet.
 *
 * Every decision is decoded and compiled through the same OosSemanticAnnotationDecoder,
 * OosSemanticAnnotationValidator and CompileOosSemanticAnnotations pipeline the live candidate
 * uses, so truth.expected_plans can never be typed independently of truth.annotations: it is
 * regenerated from them by the same deterministic compiler that later scores the candidate. The
 * frozen source inventory, legacy prefill and IC3 authority are never rewritten; only truth is
 * populated, one source at a time, and a source without a decision remains pending.
 */
class AdjudicateOosSemanticEvaluationCorpus
{
    public function __construct(
        private readonly OosSemanticAnnotationDecoder $decoder,
        private readonly CompileOosSemanticAnnotations $compiler,
    ) {}

    /**
     * @param  array<string, mixed>  $corpus  the frozen -prefilled worksheet
     * @param  array<string, array<string, mixed>>  $decisions  item_key => {services, annotations, adjudicated_by, adjudicated_at}
     * @return array<string, mixed>
     */
    public function build(array $corpus, array $decisions): array
    {
        $this->assertFrozenCorpus($corpus);

        $sources = $corpus['sources'];
        $knownKeys = array_column($sources, 'item_key');

        foreach (array_diff(array_keys($decisions), $knownKeys) as $unknownKey) {
            throw new RuntimeException("Adjudication decision references unknown source {$unknownKey}.");
        }

        $adjudicatedSources = array_map(
            fn (array $source): array => $this->adjudicateSource($source, $decisions[$source['item_key']] ?? null),
            $sources,
        );

        $sourceCount = count($adjudicatedSources);
        $fullyAdjudicated = count(array_filter(
            $adjudicatedSources,
            static fn (array $source): bool => $source['truth']['adjudication_state'] === 'adjudicated',
        ));
        $scoreable = $fullyAdjudicated === $sourceCount;

        $corpus['sources'] = $adjudicatedSources;
        $corpus['completeness'] = [
            'source_count' => $sourceCount,
            'fully_adjudicated_sources' => $fullyAdjudicated,
            'pending_sources' => $sourceCount - $fullyAdjudicated,
            'scoreable' => $scoreable,
            'blocking_fields' => $scoreable ? [] : ['truth.services', 'truth.annotations', 'truth.expected_plans'],
        ];
        unset($corpus['corpus_hash']);
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  ?array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function adjudicateSource(array $source, ?array $decision): array
    {
        if ($decision === null) {
            return $source;
        }

        $itemKey = $source['item_key'];
        $adjudicatedBy = $decision['adjudicated_by'] ?? null;
        $adjudicatedAt = $decision['adjudicated_at'] ?? null;

        if (! is_string($adjudicatedBy) || $adjudicatedBy === '') {
            throw new RuntimeException("Adjudication decision for {$itemKey} has no adjudicator identity.");
        }

        if (! is_string($adjudicatedAt) || $adjudicatedAt === '' || ! $this->isTimestamp($adjudicatedAt)) {
            throw new RuntimeException("Adjudication decision for {$itemKey} has no valid adjudication timestamp.");
        }

        $document = $this->sourceDocument($source);
        $result = $this->decoder->decode($document, $decision);
        $compilation = $this->compiler->compile($document, $result);

        $source['truth'] = [
            'format' => FreezeOosSemanticEvaluationCorpus::TruthFormat,
            'version' => FreezeOosSemanticEvaluationCorpus::TruthVersion,
            'adjudication_state' => 'adjudicated',
            'services' => array_map(
                static fn (OosCandidateService $service): array => $service->toArray(),
                $result->services,
            ),
            'annotations' => array_values(array_map(
                static fn (OosSemanticLineAnnotation $annotation): array => $annotation->toArray(),
                $result->annotations,
            )),
            'expected_plans' => $compilation->extraction->services,
            'adjudicated_by' => $adjudicatedBy,
            'adjudicated_at' => $adjudicatedAt,
        ];

        return $source;
    }

    /** @param array<string, mixed> $corpus */
    private function assertFrozenCorpus(array $corpus): void
    {
        if (($corpus['format'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Format
            || ($corpus['version'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Version) {
            throw new RuntimeException('Frozen semantic evaluation corpus has an unsupported format or version.');
        }

        $recordedHash = $corpus['corpus_hash'] ?? null;
        $withoutHash = $corpus;
        unset($withoutHash['corpus_hash']);

        if (! is_string($recordedHash) || ! hash_equals($recordedHash, CanonicalJson::hash($withoutHash))) {
            throw new RuntimeException('Frozen semantic evaluation corpus hash does not match its contents.');
        }

        if (! is_array($corpus['sources'] ?? null) || $corpus['sources'] === []) {
            throw new RuntimeException('Frozen semantic evaluation corpus contains no sources.');
        }
    }

    /** @param array<string, mixed> $source */
    private function sourceDocument(array $source): OosEmailSourceDocument
    {
        $portable = $source['source_document'];
        $physical = [];

        foreach ($portable['lines'] as $line) {
            $physical[(int) $line['physical_position']] = (string) $line['exact_text'];
        }

        if ($physical === []) {
            throw new RuntimeException("Semantic source {$source['item_key']} contains no physical lines.");
        }

        $last = max(array_keys($physical));
        $body = [];

        for ($position = 1; $position <= $last; $position++) {
            $body[] = $physical[$position] ?? '';
        }

        $document = OosEmailSourceDocument::fromContext(
            is_string($portable['subject'] ?? null) ? $portable['subject'] : null,
            implode("\n", $body),
            is_string($portable['received_date'] ?? null) ? $portable['received_date'] : null,
        );

        if (! hash_equals($portable['input_hash'], $document->inputHash())) {
            throw new RuntimeException("Reconstructed semantic source {$source['item_key']} changed hash.");
        }

        return $document;
    }

    private function isTimestamp(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

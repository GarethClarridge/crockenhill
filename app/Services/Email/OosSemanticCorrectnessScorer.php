<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Enums\SermonService;
use App\Support\CanonicalJson;
use App\Support\RepositoryCommit;
use RuntimeException;
use Throwable;

/**
 * Scores one semantic-parser candidate against the adjudicated private truth corpus (§6.2), and
 * reports every §6.3 acceptance gate separately.
 *
 * Three properties are load-bearing.
 *
 * - **No inferential label on drifted or incomplete inputs.** Delivery 6's acceptance says a
 *   comparison must not carry a verdict when truth is incomplete or the inputs drifted. Those are
 *   *refusals*, not failures: a refused artifact records what could not be established and emits no
 *   metrics at all, so nobody can read a partial score as a weak pass.
 * - **Every gate is reported independently.** A single overall verdict hides which gate moved, and
 *   §6.3 explicitly forbids a cost or stability figure from overriding a correctness or safety gate.
 *   A gate this artifact cannot establish is `not_scored`, which blocks a `pass` verdict without
 *   being reported as evidence of failure.
 * - **Correctness is measured against the compiler's own output.** `truth.expected_plans` was
 *   compiled from the adjudicated annotations by {@see CompileOosSemanticAnnotations}, and the
 *   candidate's plans by the same class. Comparing them therefore isolates the *annotation*
 *   difference. Where a figure is instead measured against the approved manifest identity — the
 *   §6.3 gate 7 identity comparison — the adjudicated truth's own score is reported beside it as the
 *   ceiling, because a deterministic resolver the candidate cannot exceed is not a model finding.
 *
 * Deleted with the rest of the Delivery 0/6 evaluation surface at an accepted comparison or historic
 * import IC8 closeout, whichever comes first.
 */
class OosSemanticCorrectnessScorer
{
    public const string Format = 'crockenhill-oos-semantic-correctness-score';

    public const int Version = 1;

    /** §6.3 gate 6. */
    private const float ItemPrecisionFloor = 0.98;

    private const float ItemRecallFloor = 0.85;

    /**
     * The four compiler-produced states §6.3 gate 2 requires to be structurally impossible, named in
     * the legacy content/bookkeeping validator's own rule codes so the gate is measured by the rule
     * that defines them rather than by a restatement of it.
     */
    private const array BookkeepingDefectCodes = [
        'items_out_of_source_order',
        'source_line_claimed_by_multiple_items',
        'line_ignored_and_claimed',
        'line_ignored_inside_item_span',
    ];

    public function __construct(
        private readonly OosSemanticEvaluationCorpusGate $gate,
        private readonly OosEmailExtractionValidator $extractionValidator,
        private readonly OosParserSurfaceFingerprint $fingerprint,
        private readonly OosSemanticEvaluationSource $sources = new OosSemanticEvaluationSource,
    ) {}

    /**
     * @param  array<string, mixed>  $corpus  adjudicated private truth corpus
     * @param  array<string, mixed>  $candidate  candidate evidence artifact
     * @param  array<string, mixed>  $baseline  banked legacy stability diagnostic (the 24/60 baseline)
     * @param  array<string, mixed>  $safetyFixtures  results from {@see RunOosSemanticSafetyFixtures}
     * @param  array<string, mixed>|null  $replicate  a second candidate evidence artifact, when replicates exist
     * @return array<string, mixed>
     */
    public function score(
        array $corpus,
        array $candidate,
        array $baseline,
        array $safetyFixtures,
        ?array $replicate = null,
    ): array {
        $this->assertShape($corpus, $candidate, $baseline, $safetyFixtures, $replicate);

        $surface = $this->fingerprint->fingerprint();
        $report = [
            'format' => self::Format,
            'version' => self::Version,
            'inputs' => $this->inputs($corpus, $candidate, $baseline, $safetyFixtures, $replicate, $surface),
        ];

        $refusals = $this->refusals($corpus, $candidate, $baseline, $replicate, $surface);

        if ($refusals !== []) {
            $report['inference'] = [
                'label' => 'refused',
                'refusals' => $refusals,
                'note' => 'Truth is incomplete or an input drifted, so no metric and no gate verdict is '
                    .'emitted. A partial score would otherwise be readable as a weak pass.',
            ];
            $report['metrics'] = null;
            $report['gates'] = null;
            $report['verdict'] = null;
            $report['score_hash'] = CanonicalJson::hash($report);

            return $report;
        }

        $sources = $this->analyseSources($corpus, $candidate);
        $metrics = $this->metrics($sources, $corpus, $candidate, $baseline, $safetyFixtures, $replicate);
        $gates = $this->gates($metrics, $safetyFixtures);

        $report['inference'] = ['label' => 'scored', 'refusals' => [], 'note' => null];
        $report['metrics'] = $metrics;
        $report['gates'] = $gates;
        $report['verdict'] = $this->verdict($gates);
        $report['score_hash'] = CanonicalJson::hash($report);

        return $report;
    }

    // -----------------------------------------------------------------------------------------
    // Fail-closed input shape
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $safetyFixtures
     * @param  array<string, mixed>|null  $replicate
     */
    private function assertShape(array $corpus, array $candidate, array $baseline, array $safetyFixtures, ?array $replicate): void
    {
        if (($corpus['format'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Format
            || ($corpus['version'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Version) {
            throw new RuntimeException('The truth corpus is not a supported semantic evaluation corpus.');
        }

        foreach (['candidate' => $candidate, 'replicate' => $replicate] as $label => $artifact) {
            if ($artifact === null) {
                continue;
            }

            if (($artifact['format'] ?? null) !== OosSemanticCandidateEvidenceRunner::Format
                || ($artifact['version'] ?? null) !== OosSemanticCandidateEvidenceRunner::Version) {
                throw new RuntimeException("The {$label} artifact is not a supported semantic candidate evidence artifact.");
            }

            if (! is_array($artifact['results'] ?? null) || $artifact['results'] === []) {
                throw new RuntimeException("The {$label} artifact carries no per-source results.");
            }
        }

        if (($baseline['format'] ?? null) !== 'crockenhill-oos-parser-stability-diagnostic'
            || ! is_array($baseline['stability']['validation'] ?? null)) {
            throw new RuntimeException('The baseline artifact is not a stability diagnostic carrying first-pass validation counts.');
        }

        if (($safetyFixtures['format'] ?? null) !== RunOosSemanticSafetyFixtures::Format
            || ($safetyFixtures['version'] ?? null) !== RunOosSemanticSafetyFixtures::Version
            || ! is_array($safetyFixtures['summary'] ?? null)) {
            throw new RuntimeException('The safety fixture results are not a supported fixture-result artifact.');
        }
    }

    /**
     * Everything that must be established before a figure means anything.
     *
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>|null  $replicate
     * @param  array{hash:string,files:array<string,string>}  $surface
     * @return list<string>
     */
    private function refusals(array $corpus, array $candidate, array $baseline, ?array $replicate, array $surface): array
    {
        $refusals = [];
        $recordedHash = $corpus['corpus_hash'] ?? null;
        $withoutHash = $corpus;
        unset($withoutHash['corpus_hash']);

        if (! is_string($recordedHash) || ! hash_equals($recordedHash, CanonicalJson::hash($withoutHash))) {
            $refusals[] = 'The truth corpus does not reproduce its own corpus hash, so its contents have drifted since it was written.';

            return $refusals;
        }

        try {
            $this->gate->assertScoreable($corpus);
        } catch (Throwable $exception) {
            $refusals[] = 'Truth is not fully adjudicated: '.$exception->getMessage();
        }

        $baselineHash = CanonicalJson::hash($baseline);
        $declaredBaseline = $corpus['inputs']['stability_diagnostic_sha256'] ?? null;

        if (! is_string($declaredBaseline) || ! hash_equals($declaredBaseline, $baselineHash)) {
            $refusals[] = 'The supplied baseline stability diagnostic is not the one the truth corpus was frozen against.';
        }

        foreach (['candidate' => $candidate, 'replicate' => $replicate] as $label => $artifact) {
            if ($artifact === null) {
                continue;
            }

            $refusals = [...$refusals, ...$this->armRefusals($corpus, $artifact, $label, $recordedHash, $surface)];
        }

        return $refusals;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $artifact
     * @param  array{hash:string,files:array<string,string>}  $surface
     * @return list<string>
     */
    private function armRefusals(array $corpus, array $artifact, string $label, string $corpusHash, array $surface): array
    {
        $refusals = [];
        $boundCorpus = $artifact['inputs']['corpus_hash'] ?? null;

        if (! is_string($boundCorpus) || ! hash_equals($corpusHash, $boundCorpus)) {
            $refusals[] = "The {$label} arm ran against a different corpus from the truth supplied here.";
        }

        $armSurface = $artifact['candidate']['parser_surface']['hash'] ?? null;

        if (! is_string($armSurface) || ! hash_equals($surface['hash'], $armSurface)) {
            $refusals[] = "The {$label} arm ran a different parser surface from the one scoring it, so its plans "
                .'and the adjudicated truth were not compiled by the same code.';
        }

        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];

        foreach ($this->list($artifact, 'results') as $result) {
            $key = $result['item_key'] ?? null;

            if (! is_string($key)) {
                $refusals[] = "The {$label} arm carries a result without a source key.";

                continue;
            }

            if (isset($byKey[$key])) {
                $refusals[] = "The {$label} arm lists source {$key} more than once.";
            }

            $byKey[$key] = $result;
        }

        foreach ($this->list($corpus, 'sources') as $record) {
            $key = is_string($record['item_key'] ?? null) ? $record['item_key'] : null;

            if ($key === null || ! isset($byKey[$key])) {
                $refusals[] = "The {$label} arm did not parse corpus source ".($key ?? 'with no key').'.';

                continue;
            }

            $expected = $record['source_document']['input_hash'] ?? null;
            $actual = $byKey[$key]['source_hash'] ?? null;

            if (! is_string($expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
                $refusals[] = "The {$label} arm parsed source {$key} from different input than the truth corpus holds.";
            }

            unset($byKey[$key]);
        }

        foreach (array_keys($byKey) as $extra) {
            $refusals[] = "The {$label} arm parsed source {$extra}, which is not in the truth corpus.";
        }

        return $refusals;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $safetyFixtures
     * @param  array<string, mixed>|null  $replicate
     * @param  array{hash:string,files:array<string,string>}  $surface
     * @return array<string, mixed>
     */
    private function inputs(array $corpus, array $candidate, array $baseline, array $safetyFixtures, ?array $replicate, array $surface): array
    {
        return [
            'corpus_hash' => $corpus['corpus_hash'] ?? null,
            'corpus_source_count' => $corpus['completeness']['source_count'] ?? null,
            'candidate_evidence_hash' => $candidate['evidence_hash'] ?? null,
            'replicate_evidence_hash' => $replicate === null ? null : ($replicate['evidence_hash'] ?? null),
            'baseline_stability_diagnostic_sha256' => CanonicalJson::hash($baseline),
            'safety_fixture_results_hash' => $safetyFixtures['fixture_results_hash'] ?? null,
            'price_snapshot_sha256' => $candidate['inputs']['price_snapshot_sha256'] ?? null,
            'scoring_parser_surface_hash' => $surface['hash'],
            'candidate' => $candidate['candidate'] ?? null,
            'application_commit' => RepositoryCommit::current(),
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Per-source analysis
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @return list<array<string, mixed>>
     */
    private function analyseSources(array $corpus, array $candidate): array
    {
        $results = [];

        foreach ($this->list($candidate, 'results') as $result) {
            $results[(string) $result['item_key']] = $result;
        }

        $analysed = [];

        foreach ($this->list($corpus, 'sources') as $record) {
            $key = (string) $record['item_key'];
            $analysed[] = $this->analyseSource($record, $results[$key], $this->sources->document($record));
        }

        return $analysed;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function analyseSource(array $record, array $result, OosEmailSourceDocument $source): array
    {
        /** @var array<string, mixed> $truth */
        $truth = $record['truth'];
        /** @var array<string, mixed> $extraction */
        $extraction = $result['extraction'];
        $attempt = $this->list($result, 'attempts')[0] ?? [];
        /** @var array<string, mixed> $attempt */
        $annotations = is_array($attempt['final_annotations'] ?? null) ? $attempt['final_annotations'] : ['services' => [], 'annotations' => []];

        $truthPlans = $this->planList($truth['expected_plans'] ?? null);
        $candidatePlans = $this->planList($extraction['services'] ?? null);
        $pairs = $this->pairPlans($truthPlans, $candidatePlans);

        return [
            'item_key' => (string) $record['item_key'],
            'stability_sample' => ($record['selection']['stability_sample'] ?? null) === true,
            'authority' => is_array($record['authority'] ?? null) ? $record['authority'] : [],
            'legacy' => is_array($record['legacy_machine_prefill'] ?? null) ? $record['legacy_machine_prefill'] : [],
            'lines' => $this->lineIdentity($source, $annotations),
            'compatibility' => $this->compatibility($source, $record, $extraction),
            'plans' => $pairs,
            'truth_plan_count' => count($truthPlans),
            'candidate_plan_count' => count($candidatePlans),
            'titles' => $this->titleBinding($source, $candidatePlans),
            'boundaries' => $this->boundaries($truth, $annotations),
            'continuations' => $this->continuations($truth, $annotations),
            'routing' => $this->routing($candidatePlans, $pairs),
            'repair' => $this->repair($attempt),
            'risk_signals' => is_array($result['risk_signals'] ?? null) ? $result['risk_signals'] : [],
            'first_pass_failed' => $this->stringList($attempt['initial_rule_codes'] ?? null) !== [],
        ];
    }

    /**
     * §6.3 gate 1: every source line annotated exactly once, nothing invented.
     *
     * @param  array<string, mixed>  $annotations
     * @return array{expected:int,annotated:int,missing:list<int>,invented:list<int>,duplicated:list<int>}
     */
    private function lineIdentity(OosEmailSourceDocument $source, array $annotations): array
    {
        $seen = [];
        $duplicated = [];

        foreach ($this->annotationList($annotations) as $annotation) {
            $lineId = $annotation['line_id'] ?? null;

            if (! is_int($lineId)) {
                continue;
            }

            if (isset($seen[$lineId])) {
                $duplicated[] = $lineId;
            }

            $seen[$lineId] = true;
        }

        $annotated = array_keys($seen);
        $expected = $source->lineIds();

        return [
            'expected' => count($expected),
            'annotated' => count($annotated),
            'missing' => array_values(array_diff($expected, $annotated)),
            'invented' => array_values(array_diff($annotated, $expected)),
            'duplicated' => array_values(array_unique($duplicated)),
        ];
    }

    /**
     * The candidate's compiled extraction, run back through the legacy content/bookkeeping validator.
     *
     * Gate 2 and gate 10 are both defined in that validator's rule families, so the candidate is
     * measured by the same rule the 24/60 baseline was measured by rather than by a semantic
     * restatement of it that could disagree.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $extraction
     * @return array{content:list<string>,bookkeeping:list<string>}
     */
    private function compatibility(OosEmailSourceDocument $source, array $record, array $extraction): array
    {
        $services = array_map($this->typedPlan(...), $this->list($extraction, 'services'));
        $validation = $this->extractionValidator->validate(
            $source,
            new OosEmailItemExtractionResult(
                items: array_map($this->typedItem(...), $this->list($extraction, 'items')),
                confidence: (float) ($extraction['confidence'] ?? 0.0),
                notes: $this->stringList($extraction['notes'] ?? null),
                services: $services,
                serviceCount: is_int($extraction['service_count'] ?? null) ? $extraction['service_count'] : null,
                ignoredLines: array_map($this->typedIgnoredLine(...), $this->list($extraction, 'ignored_lines')),
                provenanceComplete: ($extraction['provenance_complete'] ?? null) === true,
            ),
            is_string($record['source_document']['subject'] ?? null) ? $record['source_document']['subject'] : null,
        );

        return ['content' => $validation->contentRuleCodes(), 'bookkeeping' => $validation->bookkeepingRuleCodes()];
    }

    /**
     * A compiled item, narrowed to the shape the validator's contract declares.
     *
     * A field with the wrong type is a defect in the artifact, not something to coerce quietly: the
     * whole point of running the real validator is that it sees what the parser actually emitted.
     *
     * @param  array<string, mixed>  $item
     * @return array{type:string,title:string,source_line_ids:list<int>,continuation:bool,semantic_kind:?string}
     */
    private function typedItem(array $item): array
    {
        if (! is_string($item['type'] ?? null) || ! is_string($item['title'] ?? null)) {
            throw new RuntimeException('A candidate item carries no string type and title.');
        }

        return [
            'type' => $item['type'],
            'title' => $item['title'],
            'source_line_ids' => $this->integerList($item['source_line_ids'] ?? null),
            'continuation' => ($item['continuation'] ?? null) === true,
            'semantic_kind' => is_string($item['semantic_kind'] ?? null) ? $item['semantic_kind'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{service:?string,date:?string,content_scope:string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool,semantic_kind:?string}>,confidence:float}
     */
    private function typedPlan(array $plan): array
    {
        return [
            'service' => is_string($plan['service'] ?? null) ? $plan['service'] : null,
            'date' => is_string($plan['date'] ?? null) ? $plan['date'] : null,
            'content_scope' => is_string($plan['content_scope'] ?? null) ? $plan['content_scope'] : 'full',
            'service_evidence_line_ids' => $this->integerList($plan['service_evidence_line_ids'] ?? null),
            'items' => array_map($this->typedItem(...), $this->list($plan, 'items')),
            'confidence' => (float) ($plan['confidence'] ?? 0.0),
        ];
    }

    /**
     * @param  array<string, mixed>  $ignored
     * @return array{line_id:int,reason:string}
     */
    private function typedIgnoredLine(array $ignored): array
    {
        if (! is_int($ignored['line_id'] ?? null) || ! is_string($ignored['reason'] ?? null)) {
            throw new RuntimeException('A candidate ignored-line record carries no integer line ID and reason.');
        }

        return ['line_id' => $ignored['line_id'], 'reason' => $ignored['reason']];
    }

    /**
     * Pairs truth plans with candidate plans by the source lines they actually claim.
     *
     * Keying by service and date would misalign exactly where it matters: a candidate that named the
     * wrong slot or resolved the wrong date would be scored as having missed one service and invented
     * another, hiding the item-level agreement underneath. Overlap of claimed item lines, then of
     * service evidence lines, identifies the same physical part of the email whatever it was labelled;
     * leftovers are zipped in source order so a total mismatch still shows as a content difference
     * rather than being double-counted as both a miss and an invention.
     *
     * @param  list<array<string, mixed>>  $truthPlans
     * @param  list<array<string, mixed>>  $candidatePlans
     * @return list<array<string, mixed>>
     */
    private function pairPlans(array $truthPlans, array $candidatePlans): array
    {
        $scores = [];

        foreach ($truthPlans as $truthIndex => $truthPlan) {
            foreach ($candidatePlans as $candidateIndex => $candidatePlan) {
                $itemOverlap = count(array_intersect($this->planLineIds($truthPlan), $this->planLineIds($candidatePlan)));
                $evidenceOverlap = count(array_intersect(
                    $this->integerList($truthPlan['service_evidence_line_ids'] ?? null),
                    $this->integerList($candidatePlan['service_evidence_line_ids'] ?? null),
                ));

                if ($itemOverlap === 0 && $evidenceOverlap === 0) {
                    continue;
                }

                $scores[] = ['truth' => $truthIndex, 'candidate' => $candidateIndex, 'score' => $itemOverlap * 1000 + $evidenceOverlap];
            }
        }

        usort($scores, static fn (array $left, array $right): int => [$right['score'], $left['truth'], $left['candidate']]
            <=> [$left['score'], $right['truth'], $right['candidate']]);

        $pairedTruth = [];
        $pairedCandidate = [];
        $pairs = [];

        foreach ($scores as $score) {
            if (isset($pairedTruth[$score['truth']]) || isset($pairedCandidate[$score['candidate']])) {
                continue;
            }

            $pairedTruth[$score['truth']] = true;
            $pairedCandidate[$score['candidate']] = true;
            $pairs[] = $this->comparePlans($truthPlans[$score['truth']], $candidatePlans[$score['candidate']], 'overlap', $score['candidate']);
        }

        $remainingTruth = array_values(array_diff(array_keys($truthPlans), array_keys($pairedTruth)));
        $remainingCandidate = array_values(array_diff(array_keys($candidatePlans), array_keys($pairedCandidate)));

        foreach ($remainingTruth as $offset => $truthIndex) {
            $candidateIndex = $remainingCandidate[$offset] ?? null;

            $pairs[] = $candidateIndex === null
                ? $this->comparePlans($truthPlans[$truthIndex], null, 'unmatched_truth', null)
                : $this->comparePlans($truthPlans[$truthIndex], $candidatePlans[$candidateIndex], 'source_order', $candidateIndex);
        }

        foreach (array_slice($remainingCandidate, count($remainingTruth)) as $candidateIndex) {
            $pairs[] = $this->comparePlans(null, $candidatePlans[$candidateIndex], 'unmatched_candidate', $candidateIndex);
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>|null  $truthPlan
     * @param  array<string, mixed>|null  $candidatePlan
     * @return array<string, mixed>
     */
    private function comparePlans(?array $truthPlan, ?array $candidatePlan, string $pairing, ?int $candidateIndex): array
    {
        $truthItems = $truthPlan === null ? [] : $this->itemsByLineKey($truthPlan);
        $candidateItems = $candidatePlan === null ? [] : $this->itemsByLineKey($candidatePlan);
        $shared = array_keys(array_intersect_key($truthItems, $candidateItems));

        $kindMatches = 0;
        $typeMatches = 0;

        foreach ($shared as $key) {
            $kindMatches += ($truthItems[$key]['semantic_kind'] ?? null) === ($candidateItems[$key]['semantic_kind'] ?? null) ? 1 : 0;
            $typeMatches += ($truthItems[$key]['type'] ?? null) === ($candidateItems[$key]['type'] ?? null) ? 1 : 0;
        }

        return [
            'pairing' => $pairing,
            'candidate_index' => $candidateIndex,
            'matched' => $truthPlan !== null && $candidatePlan !== null,
            'truth' => $truthPlan === null ? null : $this->planIdentity($truthPlan),
            'candidate' => $candidatePlan === null ? null : $this->planIdentity($candidatePlan),
            'service_matches' => $truthPlan !== null && $candidatePlan !== null && ($truthPlan['service'] ?? null) === ($candidatePlan['service'] ?? null),
            'date_matches' => $truthPlan !== null && $candidatePlan !== null && ($truthPlan['date'] ?? null) === ($candidatePlan['date'] ?? null),
            'scope_matches' => $truthPlan !== null && $candidatePlan !== null && ($truthPlan['content_scope'] ?? null) === ($candidatePlan['content_scope'] ?? null),
            'order_exact' => $truthPlan !== null && $candidatePlan !== null && array_keys($truthItems) === array_keys($candidateItems),
            'truth_items' => count($truthItems),
            'candidate_items' => count($candidateItems),
            'shared_items' => count($shared),
            'kind_matches' => $kindMatches,
            'type_matches' => $typeMatches,
        ];
    }

    /**
     * §6.3 gate 3: an item's title is the exact source text of the lines it binds, or nothing.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array{items:int,bound:int,unbound:list<array{title:mixed,source_line_ids:list<int>,expected:?string}>}
     */
    private function titleBinding(OosEmailSourceDocument $source, array $plans): array
    {
        $items = 0;
        $bound = 0;
        $unbound = [];

        foreach ($plans as $plan) {
            foreach ($this->list($plan, 'items') as $item) {
                $items++;
                $lineIds = $this->integerList($item['source_line_ids'] ?? null);
                $expected = $lineIds === [] ? null : $source->textFor($lineIds);

                if ($expected !== null && ($item['title'] ?? null) === $expected) {
                    $bound++;

                    continue;
                }

                $unbound[] = ['title' => $item['title'] ?? null, 'source_line_ids' => $lineIds, 'expected' => $expected];
            }
        }

        return ['items' => $items, 'bound' => $bound, 'unbound' => $unbound];
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $annotations
     * @return array<string, mixed>
     */
    private function boundaries(array $truth, array $annotations): array
    {
        $truthServices = $this->list($truth, 'services');
        $candidateServices = $this->list($annotations, 'services');
        $truthLines = $this->boundaryLineIds($truthServices);
        $candidateLines = $this->boundaryLineIds($candidateServices);

        $matchedServices = 0;
        $usedCandidates = [];

        foreach ($truthServices as $truthService) {
            $truthBoundary = $this->integerList($truthService['boundary_line_ids'] ?? null);

            foreach ($candidateServices as $index => $candidateService) {
                if (isset($usedCandidates[$index])) {
                    continue;
                }

                if (array_intersect($truthBoundary, $this->integerList($candidateService['boundary_line_ids'] ?? null)) !== []) {
                    $usedCandidates[$index] = true;
                    $matchedServices++;

                    break;
                }
            }
        }

        return [
            'truth_services' => count($truthServices),
            'candidate_services' => count($candidateServices),
            'matched_services' => $matchedServices,
            'truth_lines' => count($truthLines),
            'candidate_lines' => count($candidateLines),
            'matched_lines' => count(array_intersect($truthLines, $candidateLines)),
        ];
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $annotations
     * @return array{truth:int,candidate:int,role_matches:int,target_matches:int}
     */
    private function continuations(array $truth, array $annotations): array
    {
        $truthByLine = $this->annotationsByLine($this->annotationList($truth));
        $candidateByLine = $this->annotationsByLine($this->annotationList($annotations));

        $truthCount = 0;
        $roleMatches = 0;
        $targetMatches = 0;

        foreach ($truthByLine as $lineId => $annotation) {
            if (($annotation['role'] ?? null) !== 'continuation') {
                continue;
            }

            $truthCount++;
            $candidateAnnotation = $candidateByLine[$lineId] ?? null;

            if ($candidateAnnotation === null || ($candidateAnnotation['role'] ?? null) !== 'continuation') {
                continue;
            }

            $roleMatches++;

            if (($candidateAnnotation['continuation_target_line_id'] ?? null) === ($annotation['continuation_target_line_id'] ?? null)) {
                $targetMatches++;
            }
        }

        $candidateCount = count(array_filter(
            $candidateByLine,
            static fn (array $annotation): bool => ($annotation['role'] ?? null) === 'continuation',
        ));

        return [
            'truth' => $truthCount,
            'candidate' => $candidateCount,
            'role_matches' => $roleMatches,
            'target_matches' => $targetMatches,
        ];
    }

    /**
     * Which way the pipeline could route this source, bounded from above.
     *
     * A plan can only import unattended if its confidence reaches the auto-import threshold: every
     * other condition in the disposition rule can hold a plan but never release one, and the semantic
     * path never sets consensus. Eligibility is therefore a superset of what would actually import,
     * which is the safe direction for a safety gate — it can overstate the risk, never understate it.
     * The threshold is recorded so a config change that widened it fails this gate loudly.
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  list<array<string, mixed>>  $pairs
     * @return array<string, mixed>
     */
    private function routing(array $plans, array $pairs): array
    {
        $threshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        $importable = 0;
        $eligible = [];

        foreach ($plans as $index => $plan) {
            $servicePlan = new OosEmailServicePlan(
                service: is_string($plan['service'] ?? null) ? SermonService::tryFrom($plan['service']) : null,
                date: is_string($plan['date'] ?? null) ? $plan['date'] : null,
                items: $this->placeholderItems($plan),
                confidence: (float) ($plan['confidence'] ?? 0.0),
                needsReview: true,
                shouldImport: false,
            );

            if (! $servicePlan->isImportable()) {
                continue;
            }

            $importable++;

            if ((float) ($plan['confidence'] ?? 0.0) >= $threshold) {
                $eligible[] = ['plan_key' => $servicePlan->key(), 'plan_index' => $index, 'confidence' => (float) ($plan['confidence'] ?? 0.0)];
            }
        }

        $incorrect = [];

        foreach ($eligible as $entry) {
            $pair = $this->pairForCandidatePlan($pairs, $entry['plan_index']);

            if ($pair === null
                || $pair['service_matches'] !== true
                || $pair['date_matches'] !== true
                || $pair['scope_matches'] !== true
                || $pair['order_exact'] !== true
                || $pair['shared_items'] !== $pair['truth_items']
                || $pair['kind_matches'] !== $pair['truth_items']) {
                $incorrect[] = $entry['plan_key'];
            }
        }

        return [
            'auto_import_threshold' => $threshold,
            'importable_plans' => $importable,
            'unattended_eligible_plans' => count($eligible),
            'incorrect_unattended_imports' => $incorrect,
            'category' => match (true) {
                $eligible !== [] => 'auto_importable',
                $importable > 0 => 'review_required',
                default => 'invalid_extraction',
            },
        ];
    }

    /**
     * The plan's items in the *stored* shape, so `isImportable()` is answered by production's own
     * rule rather than by a copy of its three conditions.
     *
     * Only the presence of items is read by that rule; item normalisation belongs to the parser and
     * is not repeated here, so these carry the compiled title and position and nothing invented.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function placeholderItems(array $plan): array
    {
        $items = [];

        foreach ($this->list($plan, 'items') as $item) {
            $items[] = [
                'position' => count($items) + 1,
                'type' => is_string($item['type'] ?? null) ? $item['type'] : 'other',
                'title' => is_string($item['title'] ?? null) ? $item['title'] : '',
                'source_title' => is_string($item['title'] ?? null) ? $item['title'] : null,
                'openlp_search_title' => null,
                'metadata' => null,
            ];
        }

        return $items;
    }

    /**
     * §6.3 gate 8: a repair may touch only the lines and fields the failure named.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    private function repair(array $attempt): array
    {
        $initial = $this->annotationsByLine($this->annotationList(is_array($attempt['initial_annotations'] ?? null) ? $attempt['initial_annotations'] : []));
        $final = $this->annotationsByLine($this->annotationList(is_array($attempt['final_annotations'] ?? null) ? $attempt['final_annotations'] : []));
        $allowed = is_array($attempt['allowed_patch'] ?? null) ? $attempt['allowed_patch'] : [];
        $unrelated = [];

        foreach ($final as $lineId => $annotation) {
            if (array_key_exists($lineId, $allowed)) {
                continue;
            }

            if (($initial[$lineId] ?? null) !== $annotation) {
                $unrelated[] = $lineId;
            }
        }

        $initialCodes = $this->stringList($attempt['initial_rule_codes'] ?? null);
        $finalCodes = $this->stringList($attempt['final_rule_codes'] ?? null);

        return [
            'attempted' => ($attempt['patch'] ?? null) !== null,
            'failed' => is_string($attempt['repair_error'] ?? null),
            'unrelated_line_mutations' => $unrelated,
            'introduced_rule_codes' => array_values(array_diff($finalCodes, $initialCodes)),
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Corpus-level metrics (§6.2)
    // -----------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $safetyFixtures
     * @param  array<string, mixed>|null  $replicate
     * @return array<string, mixed>
     */
    private function metrics(
        array $sources,
        array $corpus,
        array $candidate,
        array $baseline,
        array $safetyFixtures,
        ?array $replicate,
    ): array {
        return [
            'population' => [
                'sources' => count($sources),
                'truth_plans' => array_sum(array_column($sources, 'truth_plan_count')),
                'candidate_plans' => array_sum(array_column($sources, 'candidate_plan_count')),
            ],
            'line_identity' => $this->lineIdentityMetric($sources),
            'service_boundaries' => $this->boundaryMetric($sources),
            'service_identity' => $this->identityMetric($sources),
            'items' => $this->itemMetric($sources),
            'item_kinds' => $this->kindMetric($sources),
            'continuations' => $this->continuationMetric($sources),
            'title_binding' => $this->titleMetric($sources),
            'compatibility_validation' => $this->compatibilityMetric($sources, $baseline),
            'routing' => $this->routingMetric($sources),
            'repair' => $this->repairMetric($sources),
            'risk_signal_isolation' => $this->riskSignalMetric($sources),
            'safety_fixtures' => $safetyFixtures['summary'],
            'cost' => $this->costMetric($candidate, $corpus),
            'stability' => $this->stabilityMetric($candidate, $replicate),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function lineIdentityMetric(array $sources): array
    {
        $missing = 0;
        $invented = 0;
        $duplicated = 0;
        $offenders = [];

        foreach ($sources as $source) {
            /** @var array<string, mixed> $lines */
            $lines = $source['lines'];
            $missing += count($lines['missing']);
            $invented += count($lines['invented']);
            $duplicated += count($lines['duplicated']);

            if ($lines['missing'] !== [] || $lines['invented'] !== [] || $lines['duplicated'] !== []) {
                $offenders[] = $source['item_key'];
            }
        }

        return [
            'source_lines' => array_sum(array_map(static fn (array $source): int => (int) $source['lines']['expected'], $sources)),
            'missing' => $missing,
            'invented' => $invented,
            'duplicated' => $duplicated,
            'sources_with_defects' => $offenders,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function boundaryMetric(array $sources): array
    {
        $truthServices = 0;
        $candidateServices = 0;
        $matchedServices = 0;
        $truthLines = 0;
        $candidateLines = 0;
        $matchedLines = 0;

        foreach ($sources as $source) {
            /** @var array<string, int> $boundaries */
            $boundaries = $source['boundaries'];
            $truthServices += $boundaries['truth_services'];
            $candidateServices += $boundaries['candidate_services'];
            $matchedServices += $boundaries['matched_services'];
            $truthLines += $boundaries['truth_lines'];
            $candidateLines += $boundaries['candidate_lines'];
            $matchedLines += $boundaries['matched_lines'];
        }

        return [
            'service_precision' => $this->rate($matchedServices, $candidateServices),
            'service_recall' => $this->rate($matchedServices, $truthServices),
            'line_precision' => $this->rate($matchedLines, $candidateLines),
            'line_recall' => $this->rate($matchedLines, $truthLines),
            'truth_services' => $truthServices,
            'candidate_services' => $candidateServices,
            'matched_services' => $matchedServices,
        ];
    }

    /**
     * Slot, date and scope agreement with truth, plus the §6.3 gate 7 comparison against the approved
     * manifest identity with the adjudicated truth's own score reported as the ceiling.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function identityMetric(array $sources): array
    {
        $matched = 0;
        $service = 0;
        $date = 0;
        $scope = 0;
        $candidateAuthority = 0;
        $truthAuthority = 0;
        $legacyAuthority = 0;

        foreach ($sources as $source) {
            foreach ($this->pairs($source) as $pair) {
                if ($pair['matched'] !== true) {
                    continue;
                }

                $matched++;
                $service += $pair['service_matches'] === true ? 1 : 0;
                $date += $pair['date_matches'] === true ? 1 : 0;
                $scope += $pair['scope_matches'] === true ? 1 : 0;
            }

            /** @var array<string, mixed> $authority */
            $authority = $source['authority'];
            $authorityService = is_string($authority['service'] ?? null) ? $authority['service'] : null;
            $authorityDate = is_string($authority['date'] ?? null) ? $authority['date'] : null;

            $candidateAuthority += $this->matchesAuthority($source, 'candidate', $authorityService, $authorityDate) ? 1 : 0;
            $truthAuthority += $this->matchesAuthority($source, 'truth', $authorityService, $authorityDate) ? 1 : 0;

            /** @var array<string, mixed> $legacy */
            $legacy = $source['legacy'];
            $legacyOutput = is_array($legacy['output'] ?? null) ? $legacy['output'] : [];
            $legacyAuthority += ($legacyOutput['service'] ?? null) === $authorityService
                && ($legacyOutput['date'] ?? null) === $authorityDate ? 1 : 0;
        }

        return [
            'matched_plans' => $matched,
            'service_accuracy' => $this->rate($service, $matched),
            'date_accuracy' => $this->rate($date, $matched),
            'content_scope_accuracy' => $this->rate($scope, $matched),
            'authority_identity' => [
                'rule' => 'A source is identity-correct when the arm produced at least one plan whose service '
                    .'and date equal the approved manifest identity, read from the raw parse before any '
                    .'downstream manifest backfill.',
                'sources' => count($sources),
                'candidate' => $candidateAuthority,
                'legacy_baseline' => $legacyAuthority,
                'adjudicated_truth_ceiling' => $truthAuthority,
                'note' => 'The truth ceiling is what the adjudicated annotations themselves compile to. A '
                    .'candidate cannot exceed it without inventing identity the source does not carry, so a '
                    .'candidate figure at the ceiling but under the legacy baseline is a finding about '
                    .'OosServiceDateResolver, not about the model.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function matchesAuthority(array $source, string $side, ?string $service, ?string $date): bool
    {
        foreach ($this->pairs($source) as $pair) {
            $plan = $pair[$side] ?? null;

            if (is_array($plan) && ($plan['service'] ?? null) === $service && ($plan['date'] ?? null) === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function itemMetric(array $sources): array
    {
        $truePositives = 0;
        $truthItems = 0;
        $candidateItems = 0;
        $matchedPlans = 0;
        $exactOrder = 0;

        foreach ($sources as $source) {
            foreach ($this->pairs($source) as $pair) {
                $truePositives += (int) $pair['shared_items'];
                $truthItems += (int) $pair['truth_items'];
                $candidateItems += (int) $pair['candidate_items'];

                if ($pair['matched'] !== true) {
                    continue;
                }

                $matchedPlans++;
                $exactOrder += $pair['order_exact'] === true ? 1 : 0;
            }
        }

        return [
            'truth_items' => $truthItems,
            'candidate_items' => $candidateItems,
            'true_positives' => $truePositives,
            'false_positives' => $candidateItems - $truePositives,
            'false_negatives' => $truthItems - $truePositives,
            'precision' => $this->rate($truePositives, $candidateItems),
            'recall' => $this->rate($truePositives, $truthItems),
            'exact_order_rate' => $this->rate($exactOrder, $matchedPlans),
            'matched_plans' => $matchedPlans,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function kindMetric(array $sources): array
    {
        $shared = 0;
        $kind = 0;
        $type = 0;

        foreach ($sources as $source) {
            foreach ($this->pairs($source) as $pair) {
                $shared += (int) $pair['shared_items'];
                $kind += (int) $pair['kind_matches'];
                $type += (int) $pair['type_matches'];
            }
        }

        return [
            'population' => $shared,
            'semantic_kind_accuracy' => $this->rate($kind, $shared),
            'canonical_type_accuracy' => $this->rate($type, $shared),
            'note' => 'Measured over items both sides bound to the same source lines, so an item-kind '
                .'disagreement is not confounded with a different item having been selected.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function continuationMetric(array $sources): array
    {
        $truth = 0;
        $candidate = 0;
        $roleMatches = 0;
        $targetMatches = 0;

        foreach ($sources as $source) {
            /** @var array<string, int> $continuations */
            $continuations = $source['continuations'];
            $truth += $continuations['truth'];
            $candidate += $continuations['candidate'];
            $roleMatches += $continuations['role_matches'];
            $targetMatches += $continuations['target_matches'];
        }

        return [
            'truth' => $truth,
            'candidate' => $candidate,
            'recall' => $this->rate($targetMatches, $truth),
            'precision' => $this->rate($targetMatches, $candidate),
            'role_only_matches' => $roleMatches - $targetMatches,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function titleMetric(array $sources): array
    {
        $items = 0;
        $bound = 0;
        $unbound = [];

        foreach ($sources as $source) {
            /** @var array<string, mixed> $titles */
            $titles = $source['titles'];
            $items += (int) $titles['items'];
            $bound += (int) $titles['bound'];

            foreach ($this->list($titles, 'unbound') as $entry) {
                $unbound[] = ['item_key' => $source['item_key']] + $entry;
            }
        }

        return [
            'items' => $items,
            'exactly_bound' => $bound,
            'rate' => $this->rate($bound, $items),
            'unbound' => array_slice($unbound, 0, 20),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    private function compatibilityMetric(array $sources, array $baseline): array
    {
        $sampleSources = array_values(array_filter($sources, static fn (array $source): bool => $source['stability_sample'] === true));
        $corpusCounts = $this->ruleCounts($sources);
        $sampleCounts = $this->ruleCounts($sampleSources);
        $hardCaseCounts = $this->ruleCounts(array_values(array_filter(
            $sources,
            static fn (array $source): bool => $source['stability_sample'] !== true,
        )));

        /** @var array<string, mixed> $baselineValidation */
        $baselineValidation = $baseline['stability']['validation'];
        $baselineParses = is_int($baselineValidation['parse_count'] ?? null) ? $baselineValidation['parse_count'] : 0;
        $baselineFailures = is_int($baselineValidation['first_pass_failure_parses'] ?? null) ? $baselineValidation['first_pass_failure_parses'] : 0;
        $baselineContent = is_array($baselineValidation['first_pass_rule_codes']['content'] ?? null)
            ? $baselineValidation['first_pass_rule_codes']['content']
            : [];

        $families = [];
        $codes = array_values(array_unique([
            ...array_map(strval(...), array_keys($baselineContent)),
            ...array_keys($sampleCounts['content']),
        ]));
        sort($codes);

        foreach ($codes as $code) {
            $baselineCount = is_int($baselineContent[$code] ?? null) ? $baselineContent[$code] : 0;
            $baselineRate = $this->rate($baselineCount, $baselineParses);
            $candidateRate = $this->rate($sampleCounts['content'][$code] ?? 0, count($sampleSources));
            $families[$code] = [
                'baseline_parses' => $baselineCount,
                'baseline_rate' => $baselineRate,
                'candidate_sources' => $sampleCounts['content'][$code] ?? 0,
                'candidate_rate' => $candidateRate,
                'regressed' => $candidateRate > $baselineRate,
            ];
        }

        $sampleFailures = count(array_filter($sampleSources, static fn (array $source): bool => $source['first_pass_failed'] === true));

        return [
            'comparison_population' => 'The baseline parsed the 30-source deterministic stability sample only, so the '
                .'gate 10 comparison is restricted to those sources. The eight named hard cases are reported '
                .'separately: a rule family the baseline never had the chance to hit is a population difference, '
                .'not a regression.',
            'content_rule_counts' => $corpusCounts['content'],
            'bookkeeping_rule_counts' => $corpusCounts['bookkeeping'],
            'hard_case_content_rule_counts' => $hardCaseCounts['content'],
            'hard_case_bookkeeping_rule_counts' => $hardCaseCounts['bookkeeping'],
            'bookkeeping_defect_counts' => array_map(
                static fn (string $code): int => ($corpusCounts['content'][$code] ?? 0) + ($corpusCounts['bookkeeping'][$code] ?? 0),
                array_combine(self::BookkeepingDefectCodes, self::BookkeepingDefectCodes),
            ),
            'first_pass' => [
                'candidate_failures' => $sampleFailures,
                'candidate_parses' => count($sampleSources),
                'candidate_rate' => $this->rate($sampleFailures, count($sampleSources)),
                'candidate_corpus_failures' => count(array_filter($sources, static fn (array $source): bool => $source['first_pass_failed'] === true)),
                'candidate_corpus_parses' => count($sources),
                'baseline_failures' => $baselineFailures,
                'baseline_parses' => $baselineParses,
                'baseline_rate' => $this->rate($baselineFailures, $baselineParses),
            ],
            'content_rule_families' => $families,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array{content:array<string,int>,bookkeeping:array<string,int>}
     */
    private function ruleCounts(array $sources): array
    {
        $content = [];
        $bookkeeping = [];

        foreach ($sources as $source) {
            /** @var array{content:list<string>,bookkeeping:list<string>} $compatibility */
            $compatibility = $source['compatibility'];

            foreach ($compatibility['content'] as $code) {
                $content[$code] = ($content[$code] ?? 0) + 1;
            }

            foreach ($compatibility['bookkeeping'] as $code) {
                $bookkeeping[$code] = ($bookkeeping[$code] ?? 0) + 1;
            }
        }

        ksort($content);
        ksort($bookkeeping);

        return ['content' => $content, 'bookkeeping' => $bookkeeping];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function routingMetric(array $sources): array
    {
        $categories = ['auto_importable' => 0, 'review_required' => 0, 'invalid_extraction' => 0];
        $legacyCategories = $categories;
        $eligible = 0;
        $incorrect = [];

        foreach ($sources as $source) {
            /** @var array<string, mixed> $routing */
            $routing = $source['routing'];
            $categories[(string) $routing['category']]++;
            $eligible += (int) $routing['unattended_eligible_plans'];

            foreach ($this->stringList($routing['incorrect_unattended_imports']) as $planKey) {
                $incorrect[] = ['item_key' => $source['item_key'], 'plan_key' => $planKey];
            }

            /** @var array<string, mixed> $legacy */
            $legacy = $source['legacy'];
            $legacyCategory = $legacy['routing']['category'] ?? null;

            if (is_string($legacyCategory) && array_key_exists($legacyCategory, $legacyCategories)) {
                $legacyCategories[$legacyCategory]++;
            }
        }

        return [
            'rule' => 'A plan is counted as able to import unattended when it is importable and its confidence '
                .'reaches the auto-import threshold. Every other disposition condition can only hold a plan, so '
                .'this bounds the unattended set from above and can overstate the risk but never understate it.',
            'auto_import_threshold' => (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90),
            'candidate_categories' => $categories,
            'legacy_categories' => $legacyCategories,
            'unattended_eligible_plans' => $eligible,
            'incorrect_unattended_imports' => $incorrect,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function repairMetric(array $sources): array
    {
        $attempted = 0;
        $failed = 0;
        $unrelated = [];
        $introduced = [];

        foreach ($sources as $source) {
            /** @var array<string, mixed> $repair */
            $repair = $source['repair'];
            $attempted += $repair['attempted'] === true ? 1 : 0;
            $failed += $repair['failed'] === true ? 1 : 0;

            if ($repair['unrelated_line_mutations'] !== []) {
                $unrelated[] = ['item_key' => $source['item_key'], 'line_ids' => $repair['unrelated_line_mutations']];
            }

            if ($repair['introduced_rule_codes'] !== []) {
                $introduced[] = ['item_key' => $source['item_key'], 'codes' => $repair['introduced_rule_codes']];
            }
        }

        return [
            'attempted' => $attempted,
            'failed' => $failed,
            'sources_with_unrelated_mutations' => $unrelated,
            'sources_introducing_rule_families' => $introduced,
        ];
    }

    /**
     * HIR-D8 isolation, read off the artifact: the parser must never assert corroboration it did not
     * independently establish, because a corroboration field is what finalises a dimension downstream.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function riskSignalMetric(array $sources): array
    {
        $asserted = [];

        foreach ($sources as $source) {
            /** @var array<string, mixed> $signals */
            $signals = $source['risk_signals'];

            foreach (['manifest_corroboration', 'openlp_corroboration', 'hymn_corroboration', 'catalogue_resolution'] as $field) {
                if (($signals[$field] ?? null) !== null) {
                    $asserted[] = ['item_key' => $source['item_key'], 'field' => $field];
                }
            }
        }

        return [
            'sources' => count($sources),
            'parser_asserted_corroboration' => $asserted,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    private function costMetric(array $candidate, array $corpus): array
    {
        /** @var array<string, mixed> $usage */
        $usage = is_array($candidate['usage'] ?? null) ? $candidate['usage'] : [];
        $roles = [];

        foreach ($this->list($candidate, 'calls') as $call) {
            $role = is_string($call['role'] ?? null) ? $call['role'] : 'unknown';
            $roles[$role] = ($roles[$role] ?? 0) + 1;
        }

        ksort($roles);
        $model = is_string($candidate['candidate']['configured_model'] ?? null) ? $candidate['candidate']['configured_model'] : '';
        $prices = $candidate['inputs']['price_snapshot']['models'][$model] ?? null;
        $inputPrice = is_array($prices) && is_numeric($prices['input'] ?? null) ? (float) $prices['input'] : null;
        $outputPrice = is_array($prices) && is_numeric($prices['output'] ?? null) ? (float) $prices['output'] : null;
        $inputTokens = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $outputTokens = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
        $sourceCount = max(1, count($this->list($corpus, 'sources')));
        $total = $inputPrice === null || $outputPrice === null
            ? null
            : ($inputTokens / 1_000_000 * $inputPrice) + ($outputTokens / 1_000_000 * $outputPrice);
        $perSource = $total === null ? null : $total / $sourceCount;
        $archiveSources = is_int($corpus['inputs']['approved_source_count'] ?? null) ? $corpus['inputs']['approved_source_count'] : null;

        return [
            'calls_by_role' => $roles,
            'usage' => $usage,
            'latency_ms_per_source' => $this->rate(is_int($usage['latency_ms'] ?? null) ? $usage['latency_ms'] : 0, $sourceCount),
            'price_unit' => 'USD per 1M text tokens, from the arm\'s own dated snapshot; a projection input, never a billing authority',
            'corpus_usd' => $total === null ? null : round($total, 6),
            'usd_per_source' => $perSource === null ? null : round($perSource, 8),
            'projected_weekly_usd_per_year' => $perSource === null ? null : round($perSource * 52, 6),
            'projected_archive_usd' => $perSource === null || $archiveSources === null ? null : round($perSource * $archiveSources, 6),
            'projected_archive_sources' => $archiveSources,
        ];
    }

    /**
     * Replicate self-disagreement, decomposed by the same field groups the rest of this evaluation
     * uses — {@see OosParserExtractionSignature} owns that definition so the stability figure and the
     * comparison cannot drift apart.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $replicate
     * @return array<string, mixed>|null
     */
    private function stabilityMetric(array $candidate, ?array $replicate): ?array
    {
        if ($replicate === null) {
            return null;
        }

        $first = $this->signatures($candidate);
        $second = $this->signatures($replicate);
        $groups = array_fill_keys(OosParserExtractionSignature::FieldGroups, 0);
        $disagreements = 0;
        $planKeyDisagreements = 0;

        foreach ($first as $key => $signature) {
            $other = $second[$key] ?? null;

            if ($other === null || $signature === $other) {
                continue;
            }

            $disagreements++;
            $difference = OosParserExtractionSignature::fieldDifferences($signature, $other);
            $planKeyDisagreements += $difference['plan_keys_differ'] === true ? 1 : 0;

            foreach ($this->stringList($difference['groups_that_differ']) as $group) {
                $groups[$group]++;
            }
        }

        return [
            'sources' => count($first),
            'self_disagreements' => $disagreements,
            'rate' => $this->rate($disagreements, count($first)),
            'plan_key_disagreements' => $planKeyDisagreements,
            'field_decomposition' => $groups,
            'diagnostic_ceiling' => 0.10,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function signatures(array $artifact): array
    {
        $signatures = [];

        foreach ($this->list($artifact, 'results') as $result) {
            $plans = $this->planList($result['extraction']['services'] ?? null);
            $key = (string) $result['item_key'];

            try {
                $signatures[$key] = OosParserExtractionSignature::fromPlanList(
                    array_map($this->toMetadataShape(...), $plans),
                    "candidate source {$key}",
                );
            } catch (Throwable) {
                // Duplicate or unusable plan keys are already a content-rule finding; a stability
                // figure must not be the thing that raises on them.
                $signatures[$key] = [];
            }
        }

        return $signatures;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function toMetadataShape(array $plan): array
    {
        $items = $this->list($plan, 'items');

        return [
            'plan_key' => $this->planKey($plan),
            'service' => $plan['service'] ?? null,
            'date' => $plan['date'] ?? null,
            'content_scope' => $plan['content_scope'] ?? null,
            'items' => array_map(
                static fn (array $item, int $index): array => [
                    'position' => $index + 1,
                    'type' => $item['type'] ?? null,
                    'section_type' => $item['semantic_kind'] ?? null,
                    'title' => $item['title'] ?? null,
                    'source_title' => $item['title'] ?? null,
                ],
                $items,
                array_keys($items),
            ),
            'source_provenance' => [
                'service_evidence_line_ids' => $plan['service_evidence_line_ids'] ?? [],
                'items' => array_map(
                    static fn (array $item, int $index): array => [
                        'position' => $index + 1,
                        'source_line_ids' => $item['source_line_ids'] ?? [],
                        'continuation' => ($item['continuation'] ?? false) === true,
                        'semantic_kind' => $item['semantic_kind'] ?? null,
                    ],
                    $items,
                    array_keys($items),
                ),
            ],
        ];
    }

    // -----------------------------------------------------------------------------------------
    // §6.3 gates
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $safetyFixtures
     * @return list<array<string, mixed>>
     */
    private function gates(array $metrics, array $safetyFixtures): array
    {
        /** @var array<string, mixed> $lines */
        $lines = $metrics['line_identity'];
        /** @var array<string, mixed> $compatibility */
        $compatibility = $metrics['compatibility_validation'];
        /** @var array<string, mixed> $titles */
        $titles = $metrics['title_binding'];
        /** @var array<string, mixed> $items */
        $items = $metrics['items'];
        /** @var array<string, mixed> $routing */
        $routing = $metrics['routing'];
        /** @var array<string, mixed> $identity */
        $identity = $metrics['service_identity'];
        /** @var array<string, mixed> $repair */
        $repair = $metrics['repair'];
        /** @var array<string, mixed> $risk */
        $risk = $metrics['risk_signal_isolation'];
        /** @var array<string, mixed> $summary */
        $summary = $safetyFixtures['summary'];
        /** @var array<string, int> $defects */
        $defects = $compatibility['bookkeeping_defect_counts'];
        /** @var array<string, mixed> $firstPass */
        $firstPass = $compatibility['first_pass'];
        /** @var array<string, array<string, mixed>> $families */
        $families = $compatibility['content_rule_families'];
        /** @var array<string, mixed> $authority */
        $authority = $identity['authority_identity'];

        $regressedFamilies = array_keys(array_filter($families, static fn (array $family): bool => $family['regressed'] === true));

        return [
            $this->gate(1, 'source_line_identity', true,
                $lines['missing'] === 0 && $lines['invented'] === 0 && $lines['duplicated'] === 0 ? 'pass' : 'fail',
                $lines),
            $this->gate(2, 'compiler_bookkeeping_defects', true,
                array_sum($defects) === 0 ? 'pass' : 'fail',
                ['defects' => $defects, 'content_rule_counts' => $compatibility['content_rule_counts'], 'bookkeeping_rule_counts' => $compatibility['bookkeeping_rule_counts']]),
            $this->gate(3, 'exact_title_source_binding', true,
                $titles['items'] > 0 && $titles['rate'] === 1.0 ? 'pass' : ($titles['items'] === 0 ? 'not_scored' : 'fail'),
                $titles),
            $this->gate(4, 'content_invalid_safety_fixtures_held', true,
                $summary['unsatisfied'] === 0 && $summary['content_invalid_false_accepts'] === 0 ? 'pass' : 'fail',
                $summary),
            $this->gate(5, 'zero_incorrect_unattended_imports', true,
                $routing['incorrect_unattended_imports'] === [] ? 'pass' : 'fail',
                ['rule' => $routing['rule'], 'unattended_eligible_plans' => $routing['unattended_eligible_plans'], 'incorrect_unattended_imports' => $routing['incorrect_unattended_imports']]),
            $this->gate(6, 'item_precision_and_recall', true,
                $items['precision'] >= self::ItemPrecisionFloor && $items['recall'] >= self::ItemRecallFloor ? 'pass' : 'fail',
                $items + ['precision_floor' => self::ItemPrecisionFloor, 'recall_floor' => self::ItemRecallFloor]),
            $this->gate(7, 'identity_accuracy_and_dimension_isolation', true,
                $authority['candidate'] >= $authority['legacy_baseline'] && $risk['parser_asserted_corroboration'] === [] ? 'pass' : 'fail',
                ['authority_identity' => $authority, 'truth_agreement' => array_intersect_key($identity, array_flip(['matched_plans', 'service_accuracy', 'date_accuracy', 'content_scope_accuracy'])), 'dimension_isolation' => $risk]),
            $this->gate(8, 'targeted_repair_is_local', true,
                $repair['sources_with_unrelated_mutations'] === [] && $repair['sources_introducing_rule_families'] === [] ? 'pass' : 'fail',
                $repair),
            $this->gate(9, 'weekly_and_historic_entry_point_parity', true, 'not_scored',
                ['reason' => 'Entry-point parity is a property of two code paths, not of one arm\'s output. It is '
                    .'established by the weekly/archive contract test in the suite; this artifact can only show that '
                    .'every parse in the arm ran one parser surface against the corpus source hashes, which the input '
                    .'refusals above already require.',
                    'established_by' => 'tests/Feature/Services/Email/OosParserEntryPointParityTest.php',
                    'operator_note' => 'That contract test now exists and passes. This scorer deliberately does not '
                        .'read a suite result, so the gate stays not_scored here — which means a comparison artifact '
                        .'cannot reach verdict "pass" on the scorer alone. Closing Delivery 6 therefore needs a '
                        .'maintainer decision on how this gate is discharged: either accept the suite run as the '
                        .'evidence outside the artifact, or give the scorer an explicit attested input. Do not change '
                        .'this to "pass" without that decision; it would make the scorer assert something it never '
                        .'checked.']),
            $this->gate(10, 'first_pass_validation_improvement', true,
                $firstPass['candidate_rate'] < $firstPass['baseline_rate'] && $regressedFamilies === [] ? 'pass' : 'fail',
                ['first_pass' => $firstPass, 'content_rule_families' => $families, 'regressed_families' => $regressedFamilies]),
            $this->gate(11, 'cost_latency_and_stability_reported', false,
                'pass',
                ['cost' => $metrics['cost'], 'stability' => $metrics['stability'], 'note' => $metrics['stability'] === null
                    ? 'Stability needs two replicates and Delivery 6 runs them only after correctness passes, so a '
                        .'correctness-first artifact reports none. This gate cannot override a correctness or safety gate.'
                    : 'Reported for information only; it cannot override a correctness or safety gate.']),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function gate(int $id, string $name, bool $hard, string $status, array $detail): array
    {
        return ['gate' => $id, 'name' => $name, 'hard' => $hard, 'status' => $status, 'detail' => $detail];
    }

    /**
     * A gate this artifact could not establish blocks a pass without being reported as a failure.
     *
     * @param  list<array<string, mixed>>  $gates
     */
    private function verdict(array $gates): string
    {
        foreach ($gates as $gate) {
            if ($gate['status'] === 'fail') {
                return 'fail';
            }
        }

        foreach ($gates as $gate) {
            if ($gate['status'] !== 'pass') {
                return 'incomplete';
            }
        }

        return 'pass';
    }

    // -----------------------------------------------------------------------------------------
    // Shape helpers
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function pairs(array $source): array
    {
        /** @var list<array<string, mixed>> $pairs */
        $pairs = $source['plans'];

        return $pairs;
    }

    /**
     * @param  list<array<string, mixed>>  $pairs
     * @return array<string, mixed>|null
     */
    private function pairForCandidatePlan(array $pairs, int $candidateIndex): ?array
    {
        foreach ($pairs as $pair) {
            if ($pair['candidate_index'] === $candidateIndex && $pair['matched'] === true) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{service:?string,date:?string,content_scope:?string,items:int}
     */
    private function planIdentity(array $plan): array
    {
        return [
            'service' => is_string($plan['service'] ?? null) ? $plan['service'] : null,
            'date' => is_string($plan['date'] ?? null) ? $plan['date'] : null,
            'content_scope' => is_string($plan['content_scope'] ?? null) ? $plan['content_scope'] : null,
            'items' => count($this->list($plan, 'items')),
        ];
    }

    /** @param array<string, mixed> $plan */
    private function planKey(array $plan): string
    {
        $service = is_string($plan['service'] ?? null) ? $plan['service'] : 'unknown';
        $date = is_string($plan['date'] ?? null) ? $plan['date'] : 'unknown';

        return "{$service}:{$date}";
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function itemsByLineKey(array $plan): array
    {
        $items = [];

        foreach ($this->list($plan, 'items') as $item) {
            $items[implode(',', $this->integerList($item['source_line_ids'] ?? null))] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<int>
     */
    private function planLineIds(array $plan): array
    {
        $lineIds = [];

        foreach ($this->list($plan, 'items') as $item) {
            $lineIds = [...$lineIds, ...$this->integerList($item['source_line_ids'] ?? null)];
        }

        return array_values(array_unique($lineIds));
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<int>
     */
    private function boundaryLineIds(array $services): array
    {
        $lineIds = [];

        foreach ($services as $service) {
            $lineIds = [...$lineIds, ...$this->integerList($service['boundary_line_ids'] ?? null)];
        }

        $lineIds = array_values(array_unique($lineIds));
        sort($lineIds);

        return $lineIds;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function annotationList(array $block): array
    {
        $annotations = $block['annotations'] ?? null;

        if (! is_array($annotations)) {
            return [];
        }

        return array_values(array_filter($annotations, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $annotations
     * @return array<int, array<string, mixed>>
     */
    private function annotationsByLine(array $annotations): array
    {
        $byLine = [];

        foreach ($annotations as $annotation) {
            if (is_int($annotation['line_id'] ?? null)) {
                $byLine[$annotation['line_id']] = $annotation;
            }
        }

        return $byLine;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planList(mixed $plans): array
    {
        if (! is_array($plans)) {
            return [];
        }

        return array_values(array_filter($plans, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function list(array $block, string $key): array
    {
        $value = $block[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_int(...)));
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 6);
    }
}

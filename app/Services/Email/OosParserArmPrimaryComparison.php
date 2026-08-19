<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSourceFaithfulnessVerdict;
use App\Support\CanonicalJson;
use App\Support\NewcombePairedDifference;
use RuntimeException;

/**
 * The **primary** analysis of the OoS parser model evaluation: is the candidate model at least as
 * faithful to the verbatim email as the baseline, within a declared non-inferiority margin?
 *
 * It reads the arm runners' raw-result projections and nothing else. That is not a convenience.
 * `OosArchiveIdentityResolver` runs immediately after every parse and backfills missing dates from
 * the manifest before overwriting the model's content scope with the curated one, and the archive
 * cache binding strips `raw_result` before any report sees it — so a comparison built on resolved,
 * staged data would systematically conceal the two error classes this evaluation exists to detect.
 * Workbook and OpenLP agreement stay where they belong, in the descriptive secondary diagnostic.
 *
 * Four properties are load-bearing:
 *
 * - **Fail-closed.** Every input is shape-, version- and drift-checked before a single rate is
 *   computed, and anything unexpected raises rather than degrading into a partial result. A
 *   comparison that quietly drops what it could not read produces a clean-looking answer that is
 *   false, with no later signal.
 * - **One-sidedness is three different things.** A source present in one arm and not the other means
 *   the arm did not cover the corpus, and is fatal. A curated allowance or tier that differs means
 *   the fixed inputs drifted, and is fatal. But a *model-produced* service present in one arm only
 *   is the outcome under measurement — a missed or invented service — and is scored over the union
 *   of both arms' plans with absence represented explicitly. Aborting on that would hide exactly the
 *   regression the evaluation is looking for.
 * - **Raw discordance `M` is not correctness discordance `b + c`.** A disagreement can be both arms
 *   wrong differently, so `b + c ≤ M` and neither is knowable before adjudication. `M` is computed
 *   here without labels, and sizes the labelling work; only the adjudicated split decides anything.
 * - **The unlabelled are allocated against the conclusion.** Concordant sources are never
 *   adjudicated, so their correctness is unknown; the Newcombe bound is minimised over every
 *   feasible allocation of them rather than assuming a favourable one.
 *
 * This one-shot surface is deleted once the Luna adoption report is accepted and no rerun remains,
 * or at historic-import IC8 closeout at the latest.
 */
class OosParserArmPrimaryComparison
{
    public const Format = 'crockenhill-oos-parser-arm-primary-comparison';

    public const Version = 1;

    private const ProjectionFormat = 'crockenhill-oos-parser-raw-projection';

    /**
     * Version 4 carries the routing block the routing-safety guardrail is decided on, the canary and
     * stability replicates' own telemetry, the rehearsal database's certification, and the run
     * manifest that makes a prompt, ceiling, threshold or parser change between arms detectable.
     */
    private const SupportedProjectionVersion = 4;

    private const ManifestFormat = 'crockenhill-oos-parser-run-manifest';

    private const SupportedManifestVersion = 1;

    /**
     * The only manifest keys allowed to differ, because they *are* the declared intervention.
     *
     * Everything else is compared, so a field added to the manifest later is drift-checked without
     * anyone having to remember to add it here too. `effective_reasoning_effort` is deliberately
     * absent: an effort arm declares its setting through `configured_reasoning_effort`, and the
     * effective value is what the payload actually carried, so a pair that disagrees there ran
     * differently for a reason neither arm declared.
     *
     * `prompt_variant` and `prompt_sha256` are exempt together, and exempting the hash does not open
     * a hole. It says which *text* a named variant carried, so the pair may name two variants — but
     * a variant whose text was edited between the two runs still moves `parser_surface`, which is
     * not exempt and names the file that moved.
     */
    private const ArmSpecificManifestKeys = ['arm', 'model', 'configured_reasoning_effort', 'prompt_variant', 'prompt_sha256'];

    /**
     * The curation tier the decision is taken on.
     *
     * A `partial` source names selected material only, so scoring it as a missed running order
     * would count a curation decision as a model failure. It stays out of `N_primary` and is
     * reported as its own paired scope diagnostic — but it is still adjudicated, because the
     * routing-safety guardrail is about unattended imports and does not care what tier they came
     * from.
     */
    private const PrimaryTier = 'full';

    private const Tiers = ['full', 'partial'];

    private const RoutingCategories = ['auto_importable', 'review_required', 'invalid_extraction'];

    /**
     * The acceptance statement, frozen before the arms ran: at most 3 additional source-exact
     * failures per 100 full-scope sources, provided there are zero hard-safety regressions and zero
     * candidate-only false auto-imports.
     */
    private const Margin = 0.03;

    /** Lower paired bound on source-normalised supported-item recall (§7.4 guardrail 4). */
    private const ItemRecallMargin = 0.05;

    /** Review share may move by this much in *either* direction; a fall is not automatically good. */
    private const ReviewShareBand = 0.05;

    private const CostCeilingRatio = 1.10;

    private const LatencyCeilingRatio = 1.25;

    /** Within-arm self-disagreement at or above this is material instability, not noise. */
    private const StabilityCeiling = 0.10;

    /** Hold reasons that must never appear on a plan the pipeline would import unattended. */
    private const HoldReasonsThatMustHold = ['content_invalid', 'no_items', 'missing_identity', 'unknown_content_scope'];

    /**
     * @param  array<string, mixed>  $baseline  decoded baseline raw-result projection
     * @param  array<string, mixed>  $candidate  decoded candidate raw-result projection
     * @param  array<string, mixed>|null  $truth  decoded source-faithfulness label artifact
     * @return array<string, mixed>
     */
    public function compare(array $baseline, array $candidate, ?array $truth = null): array
    {
        $baselineArm = $this->validatedArm($baseline, 'baseline');
        $candidateArm = $this->validatedArm($candidate, 'candidate');
        $this->assertNoDrift($baselineArm, $candidateArm);

        $binding = [
            'baseline_arm' => $baselineArm['arm'],
            'candidate_arm' => $candidateArm['arm'],
            'baseline_model' => $baselineArm['model'],
            'candidate_model' => $candidateArm['model'],
            'source_key_list_hash' => $baselineArm['source_key_list_hash'],
            'baseline_projection_sha256' => CanonicalJson::hash($baseline),
            'candidate_projection_sha256' => CanonicalJson::hash($candidate),
        ];

        $sources = $this->analyseSources($baselineArm, $candidateArm);
        $population = $this->population($sources);
        $discordance = $this->discordance($sources, $population['n_primary']);
        $stability = $this->stability($baselineArm, $candidateArm);

        $report = [
            'format' => self::Format,
            'version' => self::Version,
            'estimand' => 'source-exact faithfulness to the verbatim email, paired by source email, '
                .'read from the raw parse before manifest-backed identity and content-scope resolution',
            'arms' => [
                'baseline' => $this->armSummary($baselineArm),
                'candidate' => $this->armSummary($candidateArm),
            ],
            'inputs' => [
                'baseline_projection_sha256' => $binding['baseline_projection_sha256'],
                'candidate_projection_sha256' => $binding['candidate_projection_sha256'],
                'source_key_list_hash' => $baselineArm['source_key_list_hash'],
                'manifest_hash' => $baselineArm['manifest_hash'],
                'truth_sha256' => null,
                'price_snapshot_sha256' => $baselineArm['run_manifest']['inputs']['price_snapshot_sha256'],
                'parser_surface_hash' => $baselineArm['run_manifest']['parser_surface']['hash'],
                'application_commit' => $baselineArm['run_manifest']['application_commit'] ?? null,
            ],
            'population' => $population,
            'stability' => $stability,
            'discordance' => $discordance,
        ];

        if ($truth === null) {
            $report['adjudication_worksheet'] = OosSourceFaithfulnessLabels::worksheet(
                $binding,
                $this->worksheetRows($sources),
            );
            $report['primary'] = null;
            $report['guardrails'] = null;
            $report['decision'] = null;
            $report['decision_note'] = 'No truth artifact was supplied, so no decision label is emitted. '
                .'Adjudicate every row of the worksheet, then run the comparison again with --truth.';

            return $report;
        }

        $labels = OosSourceFaithfulnessLabels::fromArtifact($truth, $binding);
        $report['inputs']['truth_sha256'] = CanonicalJson::hash($truth);
        $this->assertLabelPopulation($sources, $labels);

        $primary = $this->primary($sources, $labels, $population['n_primary']);
        $guardrails = $this->guardrails($sources, $labels, $baselineArm, $candidateArm);

        $report['primary'] = $primary;
        $report['partial_tier_diagnostic'] = $this->partialTierDiagnostic($sources, $labels);
        $report['guardrails'] = $guardrails;
        $report['decision'] = $this->decision($primary, $guardrails, $stability);
        $report['decision_note'] = $this->decisionNote($primary, $guardrails, $stability);

        return $report;
    }

    // -----------------------------------------------------------------------------------------
    // Fail-closed input validation
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function validatedArm(array $projection, string $label): array
    {
        if (($projection['format'] ?? null) !== self::ProjectionFormat) {
            throw new RuntimeException("The {$label} artifact is not an OoS parser raw-result projection.");
        }

        if (($projection['version'] ?? null) !== self::SupportedProjectionVersion) {
            throw new RuntimeException("The {$label} projection is version "
                .var_export($projection['version'] ?? null, true)
                .'; this comparison reads version '.self::SupportedProjectionVersion.' only.');
        }

        $declared = [];

        foreach (['arm', 'model', 'configured_reasoning_effort', 'effective_reasoning_effort', 'returned_model', 'manifest_hash', 'source_key_list_hash'] as $key) {
            $declared[$key] = $this->requiredString($projection, $key, "{$label} projection");
        }

        $arm = $declared;
        $arm['source_count'] = $this->requiredInt($projection, 'source_count', "{$label} projection");
        $arm['stability'] = $this->requiredArray($projection, 'stability', "{$label} projection");
        $arm['certification'] = $this->certification($projection, $label);
        $arm['canary_telemetry'] = $this->auxiliaryTelemetry(
            $this->requiredArray($projection, 'canary', "{$label} projection"),
            "{$label} canary",
            $declared['returned_model'],
        );
        $arm['stability_telemetry'] = $this->auxiliaryTelemetry(
            $arm['stability'],
            "{$label} stability replicate",
            $declared['returned_model'],
        );

        $rows = $projection['raw_results'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new RuntimeException("The {$label} projection carries no raw results.");
        }

        $sources = [];
        $order = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("The {$label} projection has a malformed row at index {$index}.");
            }

            /** @var array<string, mixed> $row */
            $source = $this->validatedSource($row, $label, $declared['returned_model']);

            if (isset($sources[$source['source_key']])) {
                throw new RuntimeException("The {$label} projection lists source {$source['source_key']} more than once.");
            }

            $sources[$source['source_key']] = $source;
            $order[] = $source['source_key'];
        }

        if (count($sources) !== $arm['source_count']) {
            throw new RuntimeException("The {$label} arm is incomplete: it declares {$arm['source_count']} sources and carries ".count($sources).'.');
        }

        if (CanonicalJson::hash($order) !== $arm['source_key_list_hash']) {
            throw new RuntimeException("The {$label} projection's rows do not reproduce its own source-key list hash; it is not the set the arm bound to.");
        }

        // Last, so that a projection which does not even describe its own source set is diagnosed as
        // that rather than as a manifest that disagrees with it.
        $arm['run_manifest'] = $this->runManifest($projection, $label, $declared);
        $arm['sources'] = $sources;

        return $arm;
    }

    /**
     * The arm's run manifest, checked for shape and for agreement with the projection around it.
     *
     * A manifest that disagrees with its own projection is worse than no manifest: it would let the
     * drift check pass on values that describe a different run from the one whose results are being
     * scored.
     *
     * @param  array<string, mixed>  $projection
     * @param  array<string, string>  $declared
     * @return array<string, mixed>
     */
    private function runManifest(array $projection, string $label, array $declared): array
    {
        $manifest = $this->requiredArray($projection, 'run_manifest', "{$label} projection");

        if (($manifest['format'] ?? null) !== self::ManifestFormat) {
            throw new RuntimeException("The {$label} projection's run manifest is not an OoS parser run manifest.");
        }

        if (($manifest['version'] ?? null) !== self::SupportedManifestVersion) {
            throw new RuntimeException("The {$label} run manifest is version "
                .var_export($manifest['version'] ?? null, true)
                .'; this comparison reads version '.self::SupportedManifestVersion.' only.');
        }

        foreach (['arm', 'model', 'configured_reasoning_effort', 'effective_reasoning_effort'] as $key) {
            if (($manifest[$key] ?? null) !== $declared[$key]) {
                throw new RuntimeException("The {$label} run manifest records {$key} '"
                    .var_export($manifest[$key] ?? null, true)."' where the projection declares '{$declared[$key]}'.");
            }
        }

        $inputs = $this->requiredArray($manifest, 'inputs', "{$label} run manifest");

        foreach (['curation_manifest_hash' => 'manifest_hash', 'source_key_list_hash' => 'source_key_list_hash'] as $manifestKey => $projectionKey) {
            if (($inputs[$manifestKey] ?? null) !== $declared[$projectionKey]) {
                throw new RuntimeException("The {$label} run manifest's {$manifestKey} does not match the projection it describes.");
            }
        }

        $surface = $this->requiredArray($manifest, 'parser_surface', "{$label} run manifest");
        $this->requiredString($surface, 'hash', "{$label} parser surface");

        if (! is_array($surface['files'] ?? null) || $surface['files'] === []) {
            throw new RuntimeException("The {$label} run manifest lists no parser-surface files, so nothing proves what code the arm ran.");
        }

        $this->requiredString($inputs, 'price_snapshot_sha256', "{$label} run manifest inputs");
        $this->requiredArray($manifest, 'price_snapshot', "{$label} run manifest");

        return $manifest;
    }

    /**
     * The dated prices this arm was frozen against, read from the manifest rather than supplied at
     * comparison time so a favourable figure cannot be fitted after the results are in.
     *
     * @param  array<string, mixed>  $arm
     * @return array<string, array{input:float,output:float}>
     */
    private function prices(array $arm, string $label): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $arm['run_manifest'];
        /** @var array<string, mixed> $snapshot */
        $snapshot = $manifest['price_snapshot'];
        $models = $snapshot['models'] ?? null;

        if (! is_array($models) || $models === []) {
            throw new RuntimeException("The {$label} price snapshot carries no models block.");
        }

        $prices = [];

        foreach ($models as $model => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("The {$label} price snapshot has a malformed entry for {$model}.");
            }

            $costs = [];

            foreach (['input', 'output'] as $field) {
                $cost = $entry[$field] ?? null;

                if ((! is_float($cost) && ! is_int($cost)) || $cost < 0) {
                    throw new RuntimeException("The {$label} price snapshot is missing a usable {$field} price for {$model}.");
                }

                $costs[$field] = (float) $cost;
            }

            $prices[(string) $model] = ['input' => $costs['input'], 'output' => $costs['output']];
        }

        return $prices;
    }

    /**
     * The rehearsal database's own certification, carried into the artifact so a reader can see it
     * rather than trust that it happened.
     *
     * A populated canonical table is refused here as well as in the runner, because the parse reads
     * `church_services` to decide date plausibility: a database somebody had already staged into
     * would change hold reasons and routing for reasons that have nothing to do with the model.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, int>
     */
    private function certification(array $projection, string $label): array
    {
        $certification = $this->requiredArray($projection, 'rehearsal_certification', "{$label} projection");
        $counts = [];

        foreach ($certification as $table => $rows) {
            if (! is_int($rows)) {
                throw new RuntimeException("The {$label} projection has a malformed rehearsal certification.");
            }

            if ($rows > 0) {
                throw new RuntimeException("The {$label} arm ran against a rehearsal database whose {$table} already held {$rows} rows, so its parses saw state the other arm may not have.");
            }

            $counts[(string) $table] = $rows;
        }

        if ($counts === []) {
            throw new RuntimeException("The {$label} projection certifies no rehearsal tables.");
        }

        return $counts;
    }

    /**
     * Canary and stability calls are real billed calls, validated to the same standard as the corpus
     * but kept in their own lists.
     *
     * They are deliberately not merged into a source's telemetry: a canary source and a stability
     * source are also corpus sources, so merging would make one source look like several and inflate
     * every per-source figure derived from it.
     *
     * @param  array<string, mixed>  $block
     * @return list<array{attempt:int,latency_ms:int,input_tokens:int,output_tokens:int}>
     */
    private function auxiliaryTelemetry(array $block, string $label, string $returnedModel): array
    {
        $calls = $block['telemetry'] ?? null;

        if (! is_array($calls) || ! array_is_list($calls)) {
            throw new RuntimeException("The {$label} carries no telemetry list, so its spend is not derivable without rerunning the arm.");
        }

        $validated = [];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                throw new RuntimeException("The {$label} has a malformed telemetry call.");
            }

            /** @var array<string, mixed> $call */
            if (($call['usage_missing'] ?? null) !== false) {
                throw new RuntimeException("The {$label} has a call with no usage record.");
            }

            $responseModel = $this->requiredString($call, 'response_model', $label);

            if ($responseModel !== $returnedModel) {
                throw new RuntimeException("The {$label} was served model '{$responseModel}' where the arm declares '{$returnedModel}'.");
            }

            $usage = $this->requiredArray($call, 'usage', $label);

            $validated[] = [
                'attempt' => $this->requiredInt($call, 'attempt', $label),
                'latency_ms' => $this->requiredInt($call, 'latency_ms', $label),
                'input_tokens' => $this->requiredInt($usage, 'input_tokens', "{$label} usage"),
                'output_tokens' => $this->requiredInt($usage, 'output_tokens', "{$label} usage"),
            ];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validatedSource(array $row, string $label, string $returnedModel): array
    {
        $sourceKey = $this->requiredString($row, 'source_key', "{$label} projection row");
        $context = "{$label} source {$sourceKey}";

        $curation = $this->requiredArray($row, 'curation', $context);
        $tier = $this->requiredString($curation, 'content_scope', "{$context} curation");

        if (! in_array($tier, self::Tiers, true)) {
            throw new RuntimeException("{$context} has curation tier '{$tier}', which is neither full nor partial. A missing or unknown tier is never treated as full.");
        }

        $routing = $this->requiredArray($row, 'routing', $context);
        $category = $this->requiredString($routing, 'category', "{$context} routing");

        if (! in_array($category, self::RoutingCategories, true)) {
            throw new RuntimeException("{$context} has unknown routing category '{$category}'.");
        }

        $rawResult = $this->requiredArray($row, 'raw_result', $context);
        $plans = $rawResult['service_plans'] ?? null;

        if (! is_array($plans) || ! array_is_list($plans)) {
            throw new RuntimeException("{$context} carries no service plan list.");
        }

        return [
            'source_key' => $sourceKey,
            'input_hash' => $this->requiredString($row, 'input_hash', $context),
            'curation' => $curation,
            'tier' => $tier,
            'routing_category' => $category,
            'auto_importable_plan_keys' => $this->stringList($routing, 'auto_importable_plan_keys', "{$context} routing"),
            'raw_result_hash' => $this->requiredString($row, 'raw_result_hash', $context),
            'plans' => OosParserExtractionSignature::index($plans, $context),
            'telemetry' => $this->validatedTelemetry($row, $sourceKey, $context, $returnedModel),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{call_role:string,attempt:int,response_model:string,latency_ms:int,input_tokens:int,output_tokens:int}>
     */
    private function validatedTelemetry(array $row, string $sourceKey, string $context, string $returnedModel): array
    {
        $calls = $row['telemetry'] ?? null;

        if (! is_array($calls) || ! array_is_list($calls) || $calls === []) {
            throw new RuntimeException("{$context} carries no call telemetry, so its cost and latency are not derivable without rerunning the arm.");
        }

        $validated = [];
        $seen = [];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                throw new RuntimeException("{$context} has a malformed telemetry call.");
            }

            /** @var array<string, mixed> $call */
            if (($call['source_key'] ?? null) !== $sourceKey) {
                throw new RuntimeException("{$context} carries a telemetry call attributed to a different source.");
            }

            if (($call['usage_missing'] ?? null) !== false) {
                throw new RuntimeException("{$context} has a call with no usage record; the arm cannot be costed.");
            }

            $role = $this->requiredString($call, 'call_role', $context);
            $attempt = $this->requiredInt($call, 'attempt', $context);
            $identity = "{$role}#{$attempt}";

            if (isset($seen[$identity])) {
                throw new RuntimeException("{$context} records call role {$role} attempt {$attempt} twice; a retry would be counted as a second source.");
            }

            $seen[$identity] = true;
            $usage = $this->requiredArray($call, 'usage', $context);
            $responseModel = $this->requiredString($call, 'response_model', $context);

            if ($responseModel !== $returnedModel) {
                throw new RuntimeException("{$context} was served model '{$responseModel}' where the arm declares '{$returnedModel}'.");
            }

            $validated[] = [
                'call_role' => $role,
                'attempt' => $attempt,
                'response_model' => $responseModel,
                'latency_ms' => $this->requiredInt($call, 'latency_ms', $context),
                'input_tokens' => $this->requiredInt($usage, 'input_tokens', "{$context} usage"),
                'output_tokens' => $this->requiredInt($usage, 'output_tokens', "{$context} usage"),
            ];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $arm
     * @return array<string, mixed>
     */
    private function armSummary(array $arm): array
    {
        return [
            'arm' => $arm['arm'],
            'model' => $arm['model'],
            'configured_reasoning_effort' => $arm['configured_reasoning_effort'],
            'effective_reasoning_effort' => $arm['effective_reasoning_effort'],
            'returned_model' => $arm['returned_model'],
            'source_count' => $arm['source_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     */
    private function assertNoDrift(array $baseline, array $candidate): void
    {
        if ($baseline['model'] === $candidate['model']) {
            throw new RuntimeException("Both arms ran model '{$baseline['model']}'. A comparison of a model with itself yields a perfect, meaningless 'no difference'.");
        }

        if ($baseline['returned_model'] === $candidate['returned_model']) {
            throw new RuntimeException("Both arms were served returned model '{$baseline['returned_model']}' whatever they requested, so the two arms did not differ.");
        }

        if ($baseline['effective_reasoning_effort'] !== $candidate['effective_reasoning_effort']) {
            throw new RuntimeException('The arms ran at different effective reasoning effort, so this is not the model-only comparison the evaluation declared.');
        }

        /** @var array<string, array<string, mixed>> $baselineSources */
        $baselineSources = $baseline['sources'];
        /** @var array<string, array<string, mixed>> $candidateSources */
        $candidateSources = $candidate['sources'];

        $baselineOnly = array_keys(array_diff_key($baselineSources, $candidateSources));
        $candidateOnly = array_keys(array_diff_key($candidateSources, $baselineSources));

        // Named before the hash comparison below, which a one-sided source would also trip. The hash
        // says the two arms differ; this says which source is missing, which is what gets acted on.
        if ($baselineOnly !== [] || $candidateOnly !== []) {
            throw new RuntimeException('One-sided source emails make an arm incomplete, and an incomplete arm is never compared: '
                .'baseline only ['.implode(', ', array_slice($baselineOnly, 0, 5)).'], candidate only ['
                .implode(', ', array_slice($candidateOnly, 0, 5)).']. '
                .'Comparing the surviving intersection would let the run\'s own outcome choose the population.');
        }

        foreach (['manifest_hash', 'source_key_list_hash', 'source_count'] as $key) {
            if ($baseline[$key] !== $candidate[$key]) {
                throw new RuntimeException("The arms bound to different {$key} values; they did not measure the same corpus.");
            }
        }

        $this->assertNoManifestDrift($baseline, $candidate);

        foreach ($baselineSources as $key => $baselineSource) {
            $candidateSource = $candidateSources[$key];

            if ($baselineSource['input_hash'] !== $candidateSource['input_hash']) {
                throw new RuntimeException("Source {$key} was parsed from different input in each arm.");
            }

            if (CanonicalJson::hash($baselineSource['curation']) !== CanonicalJson::hash($candidateSource['curation'])) {
                throw new RuntimeException("Source {$key} carries a different curated decision in each arm; the fixed inputs drifted between the runs.");
            }
        }
    }

    /**
     * Everything in the run manifest except the declared intervention must be identical.
     *
     * This is the check that makes a prompt, ceiling, threshold, service tier, price snapshot or
     * parser-surface change between the two arms visible. Before the manifest existed, the
     * comparison could see the curated inputs drift but not the code or the settings that read
     * them — so a prompt edited between arm A and arm B would have been scored as a model effect.
     *
     * It is written as "compare everything except three keys" rather than a list of keys to check,
     * because a field added to the manifest later is then drift-checked by default. The failure
     * mode of the inverse is silence.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     */
    private function assertNoManifestDrift(array $baseline, array $candidate): void
    {
        /** @var array<string, mixed> $baselineManifest */
        $baselineManifest = $baseline['run_manifest'];
        /** @var array<string, mixed> $candidateManifest */
        $candidateManifest = $candidate['run_manifest'];

        foreach (self::ArmSpecificManifestKeys as $key) {
            unset($baselineManifest[$key], $candidateManifest[$key]);
        }

        if (CanonicalJson::hash($baselineManifest) === CanonicalJson::hash($candidateManifest)) {
            return;
        }

        $drifted = [];

        foreach (array_keys($baselineManifest + $candidateManifest) as $key) {
            $baselineValue = $baselineManifest[$key] ?? null;
            $candidateValue = $candidateManifest[$key] ?? null;

            if (CanonicalJson::hash($baselineValue) !== CanonicalJson::hash($candidateValue)) {
                $drifted[] = (string) $key;
            }
        }

        throw new RuntimeException('The two arms did not run the same parser: their run manifests differ on '
            .implode(', ', $drifted).'. Only an arm\'s declared intervention — its model, its reasoning '
            .'effort or its prompt variant — may differ, so this comparison would attribute a ceiling, '
            .'threshold, price or code change to the intervention under test.');
    }

    // -----------------------------------------------------------------------------------------
    // Union scoring and discordance
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return array<string, array<string, mixed>>
     */
    private function analyseSources(array $baseline, array $candidate): array
    {
        /** @var array<string, array<string, mixed>> $baselineSources */
        $baselineSources = $baseline['sources'];
        /** @var array<string, array<string, mixed>> $candidateSources */
        $candidateSources = $candidate['sources'];

        $analysed = [];

        foreach ($baselineSources as $key => $baselineSource) {
            $candidateSource = $candidateSources[$key];

            /** @var array<string, array<string, mixed>> $baselinePlans */
            $baselinePlans = $baselineSource['plans'];
            /** @var array<string, array<string, mixed>> $candidatePlans */
            $candidatePlans = $candidateSource['plans'];

            $baselineSignature = $this->extractionSignature($baselinePlans);
            $candidateSignature = $this->extractionSignature($candidatePlans);

            $extractionDiscordant = $baselineSignature !== $candidateSignature;
            $routingDiscordant = $baselineSource['routing_category'] !== $candidateSource['routing_category'];

            $analysed[$key] = [
                'source_key' => $key,
                'tier' => $baselineSource['tier'],
                'baseline' => $baselineSource,
                'candidate' => $candidateSource,
                'extraction_discordant' => $extractionDiscordant,
                'routing_discordant' => $routingDiscordant,
                'discordant' => $extractionDiscordant || $routingDiscordant,
                'plan_diff' => $this->planDiff($baselineSignature, $candidateSignature),
                'candidate_only_auto_importable' => $candidateSource['routing_category'] === 'auto_importable'
                    && $baselineSource['routing_category'] !== 'auto_importable',
            ];
        }

        return $analysed;
    }

    /**
     * What the arm extracted, with everything that is not extraction removed.
     *
     * The rule lives in {@see OosParserExtractionSignature} because the arm runner's within-arm
     * stability check has to apply exactly the same one — see that class for why.
     *
     * @param  array<string, array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function extractionSignature(array $plans): array
    {
        return OosParserExtractionSignature::fromIndexedPlans($plans);
    }

    /**
     * The union of both arms' plans, with a plan the other arm never produced shown as `null`.
     *
     * A missed or invented service is the model outcome under measurement, so it is scored rather
     * than refused — and the adjudicator has to see the absence to decide it, which is why absence
     * is an explicit null rather than a gap in the list.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return list<array{plan_key:string,baseline:mixed,candidate:mixed}>
     */
    private function planDiff(array $baseline, array $candidate): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($baseline), array_keys($candidate))));
        sort($keys);

        $diff = [];

        foreach ($keys as $key) {
            $baselinePlan = $baseline[$key] ?? null;
            $candidatePlan = $candidate[$key] ?? null;

            if ($baselinePlan === $candidatePlan) {
                continue;
            }

            $diff[] = [
                'plan_key' => (string) $key,
                'baseline' => $baselinePlan,
                'candidate' => $candidatePlan,
            ];
        }

        return $diff;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function population(array $sources): array
    {
        $byTier = array_fill_keys(self::Tiers, 0);

        foreach ($sources as $source) {
            $byTier[(string) $source['tier']]++;
        }

        return [
            'rule' => 'N_primary is the full-scope source count. A partial source names selected material '
                .'only, so scoring it as a missed running order would read a curation decision as a model '
                .'failure; it is diagnosed separately and still adjudicated for routing safety.',
            'sources' => count($sources),
            'n_primary' => $byTier[self::PrimaryTier],
            'by_tier' => $byTier,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function discordance(array $sources, int $primaryPopulation): array
    {
        $primary = 0;
        $primaryExtraction = 0;
        $primaryRoutingOnly = 0;
        $all = 0;
        $routingSafety = 0;

        foreach ($sources as $source) {
            if ($source['discordant'] === true) {
                $all++;
            }

            if ($source['candidate_only_auto_importable'] === true) {
                $routingSafety++;
            }

            if ($source['tier'] !== self::PrimaryTier) {
                continue;
            }

            if ($source['discordant'] !== true) {
                continue;
            }

            $primary++;

            if ($source['extraction_discordant'] === true) {
                $primaryExtraction++;

                continue;
            }

            $primaryRoutingOnly++;
        }

        $threshold = ((self::Margin * $primaryPopulation) / NewcombePairedDifference::Z95OneSided) ** 2;

        return [
            'definition' => 'M counts sources whose arms differ at all — identities, scope, item list, types, '
                .'order, source-line bindings or routing category. It is not b + c: a disagreement can also be '
                .'both arms wrong differently, so b + c <= M and neither is knowable before adjudication.',
            'm_primary' => $primary,
            'm_primary_extraction' => $primaryExtraction,
            'm_primary_routing_only' => $primaryRoutingOnly,
            'm_all_tiers' => $all,
            'routing_safety_adjudications' => $routingSafety,
            'labelling_threshold' => [
                'formula' => '(delta * N_primary / z)^2, the plug-in Wald sizing heuristic only — never the decision rule',
                'delta' => self::Margin,
                'n_primary' => $primaryPopulation,
                'value' => round($threshold, 4),
                'passes_at_a_tie_up_to' => (int) floor($threshold),
                'm_primary_within_threshold' => $primary <= (int) floor($threshold),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function stability(array $baseline, array $candidate): array
    {
        $baselineRate = $this->stabilityRate($baseline, 'baseline');
        $candidateRate = $this->stabilityRate($candidate, 'candidate');

        $material = $baselineRate >= self::StabilityCeiling && $candidateRate >= self::StabilityCeiling;
        $asymmetric = abs($baselineRate - $candidateRate) >= self::StabilityCeiling;

        return [
            'ceiling' => self::StabilityCeiling,
            'baseline_self_disagreement' => $baselineRate,
            'candidate_self_disagreement' => $candidateRate,
            'consequence' => match (true) {
                $asymmetric => 'inference_refused',
                $material => 'replicate_required',
                default => 'proceed',
            },
            'reading' => $asymmetric
                ? 'One arm is materially less deterministic than the other. That is itself a finding about the '
                    .'candidate: a less deterministic parser is not a safe replacement even at equal accuracy.'
                : ($material
                    ? 'Both arms disagree with themselves at or above the ceiling, so M is measuring parser noise '
                        .'as much as model choice. Two full replicates per arm are required before inference.'
                    : 'Both arms are stable, so M can be read as a difference between models rather than noise.'),
        ];
    }

    /** @param array<string, mixed> $arm */
    private function stabilityRate(array $arm, string $label): float
    {
        /** @var array<string, mixed> $stability */
        $stability = $arm['stability'];
        $rate = $stability['rate'] ?? null;

        if (! is_float($rate) && ! is_int($rate)) {
            throw new RuntimeException("The {$label} arm records no within-arm stability rate.");
        }

        return (float) $rate;
    }

    // -----------------------------------------------------------------------------------------
    // Adjudication
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function worksheetRows(array $sources): array
    {
        $rows = [];

        foreach ($sources as $source) {
            if ($source['discordant'] !== true) {
                continue;
            }

            /** @var array<string, mixed> $baseline */
            $baseline = $source['baseline'];
            /** @var array<string, mixed> $candidate */
            $candidate = $source['candidate'];

            $rows[] = [
                'source_key' => $source['source_key'],
                'curation_tier' => $source['tier'],
                'counts_toward_primary' => $source['tier'] === self::PrimaryTier,
                'discordance' => [
                    'extraction' => $source['extraction_discordant'],
                    'routing' => $source['routing_discordant'],
                ],
                'routing' => [
                    'baseline' => $baseline['routing_category'],
                    'candidate' => $candidate['routing_category'],
                ],
                'requires_routing_safety_adjudication' => $source['candidate_only_auto_importable'],
                'requires_item_counts' => $source['extraction_discordant'] === true && $source['tier'] === self::PrimaryTier,
                'plan_diff' => $source['plan_diff'],
            ];
        }

        return $rows;
    }

    /**
     * The label population is enumerated once, before any label is opened, and labelled completely.
     *
     * Both directions are fatal. A missing label leaves a source that could be a baseline-only win,
     * so accepting the set would be optional stopping. An *extra* label is a concordant source
     * somebody chose to adjudicate, and a hand-picked concordant sample would put selection back
     * into a design that removed it.
     *
     * @param  array<string, array<string, mixed>>  $sources
     */
    private function assertLabelPopulation(array $sources, OosSourceFaithfulnessLabels $labels): void
    {
        $required = [];

        foreach ($sources as $key => $source) {
            if ($source['discordant'] === true) {
                $required[$key] = true;
            }
        }

        $labelled = array_fill_keys($labels->sourceKeys(), true);
        $missing = array_keys(array_diff_key($required, $labelled));
        $extra = array_keys(array_diff_key($labelled, $required));

        if ($missing !== []) {
            throw new RuntimeException(count($missing).' raw-discordant sources are unlabelled, for example ['
                .implode(', ', array_slice($missing, 0, 5)).']. Every one of them could be a baseline-only win, '
                .'so stopping on a partial label set would be optional stopping.');
        }

        if ($extra !== []) {
            throw new RuntimeException(count($extra).' labelled sources are not raw-discordant, for example ['
                .implode(', ', array_slice($extra, 0, 5)).']. The discordant set is enumerated once; a '
                .'hand-picked concordant sample reintroduces the selection this design removed.');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function primary(array $sources, OosSourceFaithfulnessLabels $labels, int $primaryPopulation): array
    {
        $cells = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
        $concordantUnusable = 0;
        $concordant = 0;

        foreach ($sources as $source) {
            if ($source['tier'] !== self::PrimaryTier) {
                continue;
            }

            if ($source['discordant'] !== true) {
                $concordant++;

                /** @var array<string, mixed> $candidate */
                $candidate = $source['candidate'];

                // An unusable parse counts as source-incorrect, never as a missing observation. Both
                // arms produced the same unusable parse here, so this pair is known without a label.
                if ($candidate['routing_category'] === 'invalid_extraction') {
                    $concordantUnusable++;
                }

                continue;
            }

            $verdict = $labels->verdictFor((string) $source['source_key']);

            if ($verdict === null) {
                throw new RuntimeException("Source {$source['source_key']} lost its verdict between validation and scoring.");
            }

            $cells[match ($verdict) {
                OosSourceFaithfulnessVerdict::BothFaithful => 'a',
                OosSourceFaithfulnessVerdict::CandidateOnlyFaithful => 'b',
                OosSourceFaithfulnessVerdict::BaselineOnlyFaithful => 'c',
                OosSourceFaithfulnessVerdict::NeitherFaithful => 'd',
            }]++;
        }

        $unlabelledConcordant = $concordant - $concordantUnusable;

        $interval = NewcombePairedDifference::worstCaseInterval(
            $cells['b'],
            $cells['c'],
            $cells['a'],
            $cells['d'] + $concordantUnusable,
            $unlabelledConcordant,
            NewcombePairedDifference::Z95OneSided,
        );

        $descriptive = NewcombePairedDifference::worstCaseInterval(
            $cells['b'],
            $cells['c'],
            $cells['a'],
            $cells['d'] + $concordantUnusable,
            $unlabelledConcordant,
            NewcombePairedDifference::Z95TwoSided,
        );

        return [
            'method' => "Newcombe's score interval for the difference between paired proportions "
                .'(candidate - baseline), minimised over every feasible allocation of the concordant '
                .'sources nobody adjudicated. Not a paired Wald interval, whose coverage is unreliable '
                .'exactly where discordance is small or imbalanced; not McNemar, which tests b = c and '
                .'cannot express a non-zero margin.',
            'n_primary' => $primaryPopulation,
            'adjudicated' => [
                'both_faithful' => $cells['a'],
                'candidate_only_faithful' => $cells['b'],
                'baseline_only_faithful' => $cells['c'],
                'neither_faithful' => $cells['d'],
            ],
            'concordant_unusable_in_both' => $concordantUnusable,
            'unlabelled_concordant' => $unlabelledConcordant,
            'b_plus_c' => $cells['b'] + $cells['c'],
            'point_difference' => round($interval['difference'], 6),
            'lower_one_sided_95' => round($interval['lower'], 6),
            'two_sided_95' => [
                'lower' => round($descriptive['lower'], 6),
                'upper' => round($descriptive['upper'], 6),
            ],
            'worst_case_both_correct_allocation' => $interval['worst_case_both_correct'],
            'margin' => -self::Margin,
            'passes' => $interval['lower'] > -self::Margin,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function partialTierDiagnostic(array $sources, OosSourceFaithfulnessLabels $labels): array
    {
        $discordant = 0;
        $candidateOnly = 0;
        $baselineOnly = 0;

        foreach ($sources as $source) {
            if ($source['tier'] === self::PrimaryTier || $source['discordant'] !== true) {
                continue;
            }

            $discordant++;
            $verdict = $labels->verdictFor((string) $source['source_key']);

            if ($verdict === OosSourceFaithfulnessVerdict::CandidateOnlyFaithful) {
                $candidateOnly++;
            }

            if ($verdict === OosSourceFaithfulnessVerdict::BaselineOnlyFaithful) {
                $baselineOnly++;
            }
        }

        return [
            'role' => 'paired scope and safety diagnostic for partial-scope sources; never scored as a '
                .'missed full order and never in N_primary',
            'discordant' => $discordant,
            'candidate_only_faithful' => $candidateOnly,
            'baseline_only_faithful' => $baselineOnly,
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Guardrails
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $baselineArm
     * @param  array<string, mixed>  $candidateArm
     * @return list<array<string, mixed>>
     */
    private function guardrails(
        array $sources,
        OosSourceFaithfulnessLabels $labels,
        array $baselineArm,
        array $candidateArm,
    ): array {
        return [
            $this->safetyGuardrail($sources),
            $this->completenessGuardrail($sources, $baselineArm),
            $this->routingSafetyGuardrail($sources, $labels),
            $this->itemRecallGuardrail($sources, $labels),
            $this->reviewBurdenGuardrail($sources),
            $this->costGuardrail($sources, $baselineArm, $candidateArm),
            $this->latencyGuardrail($sources, $baselineArm, $candidateArm),
        ];
    }

    /**
     * Hard safety: no violation may *escape* into a plan the pipeline would import unattended.
     *
     * The plan's wording is "zero new strict source-line or phantom-line violations". Read as "the
     * candidate must never produce a single content-invalid plan" that would fail the arm on one
     * source, which contradicts a margin that tolerates three additional extraction failures per
     * hundred — so a new content-invalid plan is an *accuracy* outcome, already counted by the
     * primary, and reported here descriptively. What is treated as a safety breach is a violation
     * that no longer holds the plan: an auto-importable plan carrying a reason that must hold it.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function safetyGuardrail(array $sources): array
    {
        $escapes = ['baseline' => [], 'candidate' => []];
        $newContentInvalid = 0;

        foreach ($sources as $source) {
            foreach (['baseline', 'candidate'] as $arm) {
                /** @var array<string, mixed> $armSource */
                $armSource = $source[$arm];
                /** @var array<string, array<string, mixed>> $plans */
                $plans = $armSource['plans'];
                /** @var list<string> $autoImportable */
                $autoImportable = $armSource['auto_importable_plan_keys'];

                foreach ($autoImportable as $planKey) {
                    $holdReasons = $plans[$planKey]['hold_reasons'] ?? [];
                    $blocking = is_array($holdReasons)
                        ? array_values(array_intersect($holdReasons, self::HoldReasonsThatMustHold))
                        : [];

                    if ($blocking !== []) {
                        $escapes[$arm][] = ['source_key' => $source['source_key'], 'plan_key' => $planKey, 'hold_reasons' => $blocking];
                    }
                }
            }

            if ($this->hasContentInvalidPlan($source, 'candidate') && ! $this->hasContentInvalidPlan($source, 'baseline')) {
                $newContentInvalid++;
            }
        }

        return [
            'name' => 'safety',
            'hard' => true,
            'status' => $escapes['candidate'] === [] ? 'pass' : 'fail',
            'detail' => [
                'candidate_escaped_holds' => array_slice($escapes['candidate'], 0, 20),
                'candidate_escaped_hold_count' => count($escapes['candidate']),
                'baseline_escaped_hold_count' => count($escapes['baseline']),
                'candidate_new_content_invalid_sources' => $newContentInvalid,
                'note' => 'New content-invalid plans are an accuracy outcome the primary already counts, '
                    .'not a safety breach; a violation that failed to hold its own plan is.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function hasContentInvalidPlan(array $source, string $arm): bool
    {
        /** @var array<string, mixed> $armSource */
        $armSource = $source[$arm];
        /** @var array<string, array<string, mixed>> $plans */
        $plans = $armSource['plans'];

        foreach ($plans as $plan) {
            $holdReasons = $plan['hold_reasons'] ?? [];

            if (is_array($holdReasons) && in_array('content_invalid', $holdReasons, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $baselineArm
     * @return array<string, mixed>
     */
    private function completenessGuardrail(array $sources, array $baselineArm): array
    {
        return [
            'name' => 'completeness',
            'hard' => true,
            'status' => 'pass',
            'detail' => [
                'sources_compared' => count($sources),
                'declared_source_count' => $baselineArm['source_count'],
                'note' => 'Both arms reproduced their bound source-key list hash, every source is present in '
                    .'both, every call carries usage, and no curated input drifted. Any failure of those '
                    .'raises before a rate is computed, so this guardrail can only be reached passing.',
            ],
        ];
    }

    /**
     * Two arms can return the **same wrong items with different confidence**, so the candidate
     * crosses the auto-import threshold where the baseline was held. Extraction-only comparison is
     * blind to that, and a one-directional review-burden guard would read the resulting fall in
     * review volume as an improvement.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function routingSafetyGuardrail(array $sources, OosSourceFaithfulnessLabels $labels): array
    {
        $adjudicated = 0;
        $falseAutoImports = [];

        foreach ($sources as $source) {
            if ($source['candidate_only_auto_importable'] !== true) {
                continue;
            }

            $adjudicated++;
            $verdict = $labels->verdictFor((string) $source['source_key']);

            if ($verdict === null) {
                throw new RuntimeException("Source {$source['source_key']} became auto-importable only in the candidate arm and was not adjudicated.");
            }

            if (! $verdict->candidateFaithful()) {
                $falseAutoImports[] = [
                    'source_key' => $source['source_key'],
                    'curation_tier' => $source['tier'],
                    'verdict' => $verdict->value,
                ];
            }
        }

        return [
            'name' => 'routing_safety',
            'hard' => true,
            'status' => $falseAutoImports === [] ? 'pass' : 'fail',
            'detail' => [
                'candidate_only_auto_importable' => $adjudicated,
                'candidate_only_false_auto_imports' => $falseAutoImports,
                'rule' => 'One candidate-only auto-importable incorrect plan fails the arm. The rule spans '
                    .'both curation tiers: an unattended import is no safer for having come from a partial source.',
            ],
        ];
    }

    /**
     * Source-normalised, never pooled: a pooled corpus recall would need truth-item denominators for
     * the concordant sources nobody labelled, which do not exist. Per source the paired difference
     * is `(candidate supported − baseline supported) / truth items`, which is exactly zero wherever
     * the two arms extracted the same thing — so only the extraction-discordant sources need counts.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function itemRecallGuardrail(array $sources, OosSourceFaithfulnessLabels $labels): array
    {
        $differences = [];
        $missingCounts = [];

        foreach ($sources as $source) {
            if ($source['tier'] !== self::PrimaryTier) {
                continue;
            }

            if ($source['extraction_discordant'] !== true) {
                $differences[] = 0.0;

                continue;
            }

            $counts = $labels->itemCountsFor((string) $source['source_key']);

            if ($counts === null) {
                $missingCounts[] = (string) $source['source_key'];

                continue;
            }

            $differences[] = ($counts['candidate_supported_items'] - $counts['baseline_supported_items']) / $counts['truth_items'];
        }

        if ($missingCounts !== []) {
            throw new RuntimeException(count($missingCounts).' extraction-discordant full-scope sources carry no item counts, for example ['
                .implode(', ', array_slice($missingCounts, 0, 5)).']. Item recall cannot be bounded without them.');
        }

        $bound = $this->meanLowerBound($differences);

        return [
            'name' => 'item_recall',
            'hard' => false,
            'status' => $bound['lower'] > -self::ItemRecallMargin ? 'pass' : 'fail',
            'detail' => [
                'population' => count($differences),
                'mean_source_normalised_difference' => round($bound['mean'], 6),
                'lower_one_sided_95' => round($bound['lower'], 6),
                'margin' => -self::ItemRecallMargin,
            ],
        ];
    }

    /**
     * A fall in review volume is not automatically favourable — it is how a candidate that
     * auto-imports the same wrong items with higher confidence would first show up.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function reviewBurdenGuardrail(array $sources): array
    {
        $total = count($sources);
        $baselineReview = 0;
        $candidateReview = 0;

        foreach ($sources as $source) {
            /** @var array<string, mixed> $baseline */
            $baseline = $source['baseline'];
            /** @var array<string, mixed> $candidate */
            $candidate = $source['candidate'];

            $baselineReview += $baseline['routing_category'] === 'review_required' ? 1 : 0;
            $candidateReview += $candidate['routing_category'] === 'review_required' ? 1 : 0;
        }

        $baselineShare = $total === 0 ? 0.0 : $baselineReview / $total;
        $candidateShare = $total === 0 ? 0.0 : $candidateReview / $total;
        $change = $candidateShare - $baselineShare;

        return [
            'name' => 'review_burden',
            'hard' => false,
            'status' => abs($change) <= self::ReviewShareBand ? 'pass' : 'fail',
            'detail' => [
                'baseline_share' => round($baselineShare, 6),
                'candidate_share' => round($candidateShare, 6),
                'change' => round($change, 6),
                'band' => self::ReviewShareBand,
                'direction' => $change < 0 ? 'fewer plans held for review' : 'more plans held for review',
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $baselineArm
     * @param  array<string, mixed>  $candidateArm
     * @return array<string, mixed>
     */
    private function costGuardrail(array $sources, array $baselineArm, array $candidateArm): array
    {
        // Both arms carry the same snapshot — the manifest drift check has already refused a pair
        // that does not — so which one is read cannot change the answer.
        $priceSnapshot = $this->prices($baselineArm, 'baseline');

        $baselineCost = $this->costPerSource($sources, 'baseline', (string) $baselineArm['model'], $priceSnapshot);
        $candidateCost = $this->costPerSource($sources, 'candidate', (string) $candidateArm['model'], $priceSnapshot);
        $ratio = $baselineCost === 0.0 ? null : $candidateCost / $baselineCost;

        return [
            'name' => 'cost',
            'hard' => false,
            'status' => $ratio !== null && $ratio <= self::CostCeilingRatio ? 'pass' : 'fail',
            'detail' => [
                'baseline_usd_per_source' => round($baselineCost, 8),
                'candidate_usd_per_source' => round($candidateCost, 8),
                'ratio' => $ratio === null ? null : round($ratio, 6),
                'ceiling_ratio' => self::CostCeilingRatio,
                'baseline_arm_total_usd' => round($this->armTotalCost($sources, 'baseline', $baselineArm, $priceSnapshot), 6),
                'candidate_arm_total_usd' => round($this->armTotalCost($sources, 'candidate', $candidateArm, $priceSnapshot), 6),
                'note' => 'The ratio is corpus cost per source, where both arms have identical structure. '
                    .'The arm totals additionally include the canary and stability-replicate calls, which are '
                    .'a fixed overhead rather than per-source corpus cost but are real spend. Telemetry records '
                    .'prompt tokens without separating the cached share, so input is costed at the uncached rate '
                    .'in both arms. Prices are at parity, so a material regression here means the run is wrong '
                    .'rather than the pricing.',
            ],
        ];
    }

    /**
     * Everything the arm actually spent: the corpus, plus the canary and both stability replicates.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $arm
     * @param  array<string, array{input:float,output:float}>  $priceSnapshot
     */
    private function armTotalCost(array $sources, string $armKey, array $arm, array $priceSnapshot): float
    {
        $model = (string) $arm['model'];
        $total = $this->costPerSource($sources, $armKey, $model, $priceSnapshot) * count($sources);

        /** @var list<array{input_tokens:int,output_tokens:int}> $auxiliary */
        $auxiliary = [...$arm['canary_telemetry'], ...$arm['stability_telemetry']];
        $prices = $priceSnapshot[$model] ?? null;

        if ($prices === null) {
            throw new RuntimeException("The price snapshot carries no entry for '{$model}'.");
        }

        foreach ($auxiliary as $call) {
            $total += (($call['input_tokens'] * $prices['input']) + ($call['output_tokens'] * $prices['output'])) / 1_000_000;
        }

        return $total;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, array{input:float,output:float}>  $priceSnapshot
     */
    private function costPerSource(array $sources, string $arm, string $model, array $priceSnapshot): float
    {
        $prices = $priceSnapshot[$model] ?? null;

        if ($prices === null) {
            throw new RuntimeException("The price snapshot carries no entry for '{$model}'.");
        }

        $total = 0.0;

        foreach ($sources as $source) {
            /** @var array<string, mixed> $armSource */
            $armSource = $source[$arm];
            /** @var list<array{input_tokens:int,output_tokens:int}> $calls */
            $calls = $armSource['telemetry'];

            foreach ($calls as $call) {
                $total += (($call['input_tokens'] * $prices['input']) + ($call['output_tokens'] * $prices['output'])) / 1_000_000;
            }
        }

        return $sources === [] ? 0.0 : $total / count($sources);
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, mixed>  $baselineArm
     * @param  array<string, mixed>  $candidateArm
     * @return array<string, mixed>
     */
    private function latencyGuardrail(array $sources, array $baselineArm, array $candidateArm): array
    {
        $baseline = $this->latencies($sources, 'baseline');
        $candidate = $this->latencies($sources, 'candidate');

        $baselineP95 = $this->percentile($baseline['values'], 0.95);
        $candidateP95 = $this->percentile($candidate['values'], 0.95);
        $ratio = $baselineP95 === 0.0 ? null : $candidateP95 / $baselineP95;

        $retriesIncreased = $candidate['retries'] > $baseline['retries'];

        return [
            'name' => 'latency',
            'hard' => false,
            'status' => $ratio !== null && $ratio <= self::LatencyCeilingRatio && ! $retriesIncreased ? 'pass' : 'fail',
            'detail' => [
                'baseline_p95_ms' => $baselineP95,
                'candidate_p95_ms' => $candidateP95,
                'ratio' => $ratio === null ? null : round($ratio, 6),
                'ceiling_ratio' => self::LatencyCeilingRatio,
                'baseline_retry_calls' => $baseline['retries'],
                'candidate_retry_calls' => $candidate['retries'],
                'baseline_replicate_retry_calls' => $this->auxiliaryRetries($baselineArm),
                'candidate_replicate_retry_calls' => $this->auxiliaryRetries($candidateArm),
                'note' => 'Failure rate is zero in both arms by construction: a failed source makes the whole '
                    .'arm incomplete and an incomplete arm is never compared. Retried calls are the observable '
                    .'residue of truncation and transient failure, so they carry that half of the guardrail. '
                    .'The gate is the corpus alone, where both arms parse every source exactly once; the canary '
                    .'and stability-replicate retries are reported beside it because the replicate deliberately '
                    .'re-parses a 30-source subset twice and would over-weight those sources in a p95 or a rate.',
            ],
        ];
    }

    /** @param array<string, mixed> $arm */
    private function auxiliaryRetries(array $arm): int
    {
        /** @var list<array{attempt:int}> $calls */
        $calls = [...$arm['canary_telemetry'], ...$arm['stability_telemetry']];
        $retries = 0;

        foreach ($calls as $call) {
            $retries += $call['attempt'] > 1 ? 1 : 0;
        }

        return $retries;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array{values:list<int>,retries:int}
     */
    private function latencies(array $sources, string $arm): array
    {
        $values = [];
        $retries = 0;

        foreach ($sources as $source) {
            /** @var array<string, mixed> $armSource */
            $armSource = $source[$arm];
            /** @var list<array{attempt:int,latency_ms:int}> $calls */
            $calls = $armSource['telemetry'];

            foreach ($calls as $call) {
                $values[] = $call['latency_ms'];
                $retries += $call['attempt'] > 1 ? 1 : 0;
            }
        }

        return ['values' => $values, 'retries' => $retries];
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil($percentile * count($values)) - 1;

        return (float) $values[max(0, min($index, count($values) - 1))];
    }

    /**
     * A one-sided 95% normal bound on the mean of the per-source differences.
     *
     * The differences are exact zeros wherever the arms extracted the same thing, so the population
     * is dominated by a point mass at zero and the mean is well behaved. This is a guardrail, not
     * the primary endpoint; the primary uses a score interval precisely because it cannot rely on
     * an approximation like this one.
     *
     * @param  list<float>  $differences
     * @return array{mean:float,lower:float}
     */
    private function meanLowerBound(array $differences): array
    {
        $n = count($differences);

        if ($n === 0) {
            return ['mean' => 0.0, 'lower' => 0.0];
        }

        $mean = array_sum($differences) / $n;

        if ($n === 1) {
            return ['mean' => $mean, 'lower' => $mean];
        }

        $variance = 0.0;

        foreach ($differences as $difference) {
            $variance += ($difference - $mean) ** 2;
        }

        $standardError = sqrt($variance / ($n - 1)) / sqrt($n);

        return ['mean' => $mean, 'lower' => $mean - (NewcombePairedDifference::Z95OneSided * $standardError)];
    }

    // -----------------------------------------------------------------------------------------
    // Decision
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $primary
     * @param  list<array<string, mixed>>  $guardrails
     * @param  array<string, mixed>  $stability
     */
    private function decision(array $primary, array $guardrails, array $stability): string
    {
        if ($stability['consequence'] !== 'proceed') {
            return 'no_conclusion';
        }

        foreach ($guardrails as $guardrail) {
            if ($guardrail['status'] !== 'pass') {
                return 'stay_on_baseline';
            }
        }

        return $primary['passes'] === true ? 'adopt_candidate' : 'stay_on_baseline';
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  list<array<string, mixed>>  $guardrails
     * @param  array<string, mixed>  $stability
     */
    private function decisionNote(array $primary, array $guardrails, array $stability): string
    {
        if ($stability['consequence'] !== 'proceed') {
            return (string) $stability['reading'];
        }

        $failed = [];

        foreach ($guardrails as $guardrail) {
            if ($guardrail['status'] !== 'pass') {
                $failed[] = (string) $guardrail['name'];
            }
        }

        if ($failed !== []) {
            return 'Guardrail breach fails the arm even where the margin passes: '.implode(', ', $failed).'.';
        }

        return $primary['passes'] === true
            ? 'The lower one-sided 95% bound on candidate - baseline source-exact correctness is above the '
                .'declared margin under the least favourable allocation of the unlabelled concordant sources, '
                .'and every guardrail passed. Parity is a pass.'
            : 'The lower bound falls at or below the declared margin, so the candidate is not demonstrably a '
                .'safe replacement.';
    }

    // -----------------------------------------------------------------------------------------
    // Typed accessors — every one of these doubles as a shape check
    // -----------------------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("The {$context} is missing a usable {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredInt(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("The {$context} is missing an integer {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requiredArray(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("The {$context} is missing a {$key} object.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function stringList(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("The {$context} is missing a {$key} list.");
        }

        $strings = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new RuntimeException("The {$context} has a non-string entry in {$key}.");
            }

            $strings[] = $entry;
        }

        return $strings;
    }
}

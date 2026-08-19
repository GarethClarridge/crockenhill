<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosParserEvaluationArm;
use App\Models\InboundEmail;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\RehearsalDatabaseProvisioner;
use App\Support\CanonicalJson;
use App\Support\RepositoryCommit;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Executes one frozen OoS parser evaluation arm against the approved corpus.
 *
 * This one-shot surface is deleted once the Luna adoption report is accepted and no rerun remains,
 * or at historic-import IC8 closeout at the latest.
 */
class OosParserArmRunner
{
    private const ProjectionFormat = 'crockenhill-oos-parser-raw-projection';

    /**
     * Bumped to 2 when each source gained its `routing` block, and to 3 when the canary and
     * stability replicates gained their own telemetry and the run gained its database certification.
     *
     * Version 1 carried only the raw disposition strings, from which routing can be *recomputed* —
     * and a recomputation is a second copy of the rules that silently stops matching the first. The
     * plan objects decide it here, once, and the projection records the answer.
     *
     * Version 2's canary and stability calls were real billed calls whose usage and latency were
     * thrown away, so an arm's true spend was not derivable from its own artifact. They are exported
     * beside the corpus rather than merged into it, because a canary source and a stability source
     * are also corpus sources: merging them would make one source look like several.
     *
     * Version 4 adds the run manifest. Until it existed, a prompt, ceiling, threshold or parser
     * change between the two arms was undetectable: the comparison could see the curated inputs
     * drift but not the code or the settings that read them.
     *
     * Version 5 keeps each attempt's validation rule codes, the correction call's outcome, and the
     * threshold and plan-level inputs the routing category was decided from — and adds the stability
     * block's census of the same, so the cheap `--stability-only` screen can report a first-pass
     * failure profile too. Until then an arm could report *that* a third of its parses were
     * corrected without saying which validator families drove it, which is the question a prompt
     * intervention is aimed at.
     */
    private const ProjectionVersion = 5;

    private const ManifestFormat = 'crockenhill-oos-parser-run-manifest';

    private const ManifestVersion = 1;

    /** The pipeline's three routing outcomes for a source, most permissive first. */
    private const RoutingAutoImportable = 'auto_importable';

    private const RoutingReviewRequired = 'review_required';

    private const RoutingInvalidExtraction = 'invalid_extraction';

    /**
     * How many disagreeing replicate pairs keep their field-by-field diff.
     *
     * Raised from 10 because 10 turned out to be too few to answer the question the retained diffs
     * exist for. The 2026-08-18 diagnostic could report that Luna's provenance group moved in 17 of
     * 19 pairs but not whether those pairs were provenance-*only*, because only 10 diffs survived —
     * so the substantive-versus-bookkeeping split had to be reported as a sample of 10 rather than a
     * census, and the strongest available reading of the arm was left unavailable.
     *
     * The original worry — that a sample where most pairs differ would embed most of the corpus
     * twice — is already handled a layer down: a retained diff is a bounded summary, not the
     * extraction, capped by `OosParserExtractionSignature` at four plans and twelve items. So the
     * cap here only has to bound the *count*, and 40 retains every pair of an arm that is anywhere
     * near passing while still bounding a catastrophic one.
     */
    private const MaxRetainedStabilityDifferences = 40;

    /**
     * Sources drawn for the stability replicate when the caller names no size.
     *
     * 30 is what the banked arms used, and the sample is a prefix of a total hash order, so a larger
     * sample is a strict superset of this one — an `n = 100` run's first 30 sources are exactly the
     * 30 the banked arms drew. That is what lets one run report both a figure comparable to the
     * banked baseline and a figure with enough power to clear the ceiling: at `n = 30` the only
     * result whose one-sided 95% bound falls below the 10% ceiling is zero disagreements.
     */
    private const DefaultStabilitySampleSize = 30;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly HistoricImportProductionGuard $productionGuard,
        private readonly RehearsalDatabaseProvisioner $provisioner,
        private readonly OosParserSurfaceFingerprint $parserSurface,
        private readonly OosEmailParserService $parser,
        private readonly OosParserEvaluationTelemetry $telemetry,
    ) {}

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @param  array<string, mixed>  $priceSnapshot  the dated official prices the arms were frozen against
     * @param  bool  $stabilityOnly  diagnostic mode: runs the canary and the stability replicate,
     *                               then returns before the full corpus and writes no projection.
     *                               Exists to re-check the §6.2 step 2 stability signal cheaply
     *                               (canary plus two calls per sampled source) without re-spending
     *                               on a full arm — see
     *                               {@see stability()}. Delete alongside the rest of this one-shot
     *                               surface.
     * @param  int  $stabilitySampleSize  how many sources the replicate draws; see
     *                                    {@see DefaultStabilitySampleSize} for why a larger sample
     *                                    stays comparable to a smaller one.
     * @return array<string, mixed>
     */
    public function run(
        OosParserEvaluationArm $arm,
        array $entries,
        string $manifestHash,
        string $manifestPath,
        array $priceSnapshot,
        bool $stabilityOnly = false,
        int $stabilitySampleSize = self::DefaultStabilitySampleSize,
    ): array {
        if ($stabilitySampleSize < 1) {
            throw new RuntimeException('The stability sample must draw at least one source.');
        }

        $arm->apply();
        $configuration = $arm->resolvedConfiguration();
        $logPath = $this->isolateLog($arm);
        $certification = $this->selectAndCertifyRehearsalConnection();
        $this->telemetry->beginRun();

        $sourceKeys = array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $entries);

        if (count($sourceKeys) !== count(array_unique($sourceKeys))) {
            throw new RuntimeException('The evaluation source set contains duplicate source keys.');
        }

        $canaries = $this->canaries($entries);
        $canaryTelemetry = [];

        foreach ($canaries as $entry) {
            $canary = $this->parseEntry($entry);

            if (array_filter($canary['telemetry'], static fn (array $call): bool => $call['usage_missing'] === true) !== []) {
                throw new RuntimeException("Compatibility canary {$entry->itemKey} returned no usage telemetry.");
            }

            $canaryTelemetry = [...$canaryTelemetry, ...$canary['telemetry']];
        }

        $stability = $this->stability($entries, $manifestHash, $stabilitySampleSize);

        if ($stabilityOnly) {
            return [
                'format' => 'crockenhill-oos-parser-stability-diagnostic',
                'stability_only' => true,
                'arm' => $arm->name,
                'model' => $configuration['model'],
                'configured_reasoning_effort' => $configuration['configured_reasoning_effort'],
                'prompt_variant' => $configuration['prompt_variant'],
                'prompt_sha256' => $configuration['prompt_sha256'],
                'returned_model' => $this->telemetry->returnedModel(),
                'database_connection' => RehearsalDatabaseProvisioner::Connection,
                'database_name' => (string) $this->database->connection()->getDatabaseName(),
                'rehearsal_certification' => $certification,
                'log_file' => basename($logPath),
                /*
                 * The stability sample is drawn deterministically from the manifest hash, so these
                 * are what make a diagnostic comparable to an earlier one: same manifest hash and
                 * same source-key list means the same 30 sources were re-parsed. Without them a
                 * recheck reports a different number with no way to tell a real change from a
                 * differently-drawn sample.
                 */
                'manifest_path' => basename($manifestPath),
                'manifest_hash' => $manifestHash,
                'source_count' => count($sourceKeys),
                'source_key_list_hash' => CanonicalJson::hash($sourceKeys),
                'price_snapshot_sha256' => CanonicalJson::hash($priceSnapshot),
                'application_commit' => RepositoryCommit::current(),
                'parser_surface' => $this->parserSurface->fingerprint(),
                'canary' => [
                    'source_keys' => array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $canaries),
                    'telemetry' => $canaryTelemetry,
                ],
                'stability' => $stability,
            ];
        }

        $results = [];

        foreach ($entries as $entry) {
            try {
                $parsed = $this->parseEntry($entry);
                $results[] = $parsed['projection'] + ['telemetry' => $parsed['telemetry']];
            } catch (\Throwable $exception) {
                throw new RuntimeException("Evaluation arm '{$arm->name}' is incomplete: {$entry->itemKey} failed: {$exception->getMessage()}", previous: $exception);
            }
        }

        return [
            'format' => self::ProjectionFormat,
            'version' => self::ProjectionVersion,
            'arm' => $arm->name,
            'model' => $configuration['model'],
            'configured_reasoning_effort' => $configuration['configured_reasoning_effort'],
            'effective_reasoning_effort' => $configuration['effective_reasoning_effort'],
            'prompt_variant' => $configuration['prompt_variant'],
            'prompt_sha256' => $configuration['prompt_sha256'],
            'returned_model' => $this->telemetry->returnedModel(),
            'database_connection' => RehearsalDatabaseProvisioner::Connection,
            'database_name' => (string) $this->database->connection()->getDatabaseName(),
            'rehearsal_certification' => $certification,
            'log_file' => basename($logPath),
            'manifest_path' => basename($manifestPath),
            'manifest_hash' => $manifestHash,
            'source_count' => count($sourceKeys),
            'source_key_list_hash' => CanonicalJson::hash($sourceKeys),
            'run_manifest' => $this->runManifest($arm, $configuration, $manifestHash, CanonicalJson::hash($sourceKeys), $priceSnapshot),
            'canary' => [
                'source_keys' => array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $canaries),
                'telemetry' => $canaryTelemetry,
            ],
            'stability' => $stability,
            'raw_results' => $results,
        ];
    }

    /**
     * Route this process's log to a file of its own for the duration of the arm.
     *
     * `OPENAI_EVALUATION_ARM` already tags each usage record, but recovering one arm's calls by
     * grepping a shared `laravel.log` for that tag is not attribution — it is a reconstruction that
     * silently loses every line the tag never reached, and this application's `laravel.log` has
     * previously grown past 300MB unrotated, which is not a file anybody will grep carefully.
     *
     * The path is deliberately not recorded in the run manifest: it is arm-specific by design, and
     * the manifest is the block the comparison requires to be identical across arms.
     */
    private function isolateLog(OosParserEvaluationArm $arm): string
    {
        $path = storage_path("logs/oos-parser-arm-{$arm->name}.log");

        config([
            'logging.channels.oos_parser_arm' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                'replace_placeholders' => true,
            ],
            'logging.default' => 'oos_parser_arm',
        ]);

        return $path;
    }

    /**
     * Everything that could make two arms differ for a reason other than the declared intervention.
     *
     * The comparison drift-checks this whole block minus the three arm-specific keys, so a field
     * added here is drift-checked automatically rather than needing to be remembered twice.
     *
     * What it deliberately does **not** carry is the hymn workbook, OpenLP and catalogue hashes the
     * plan lists. An arm never reads them: they are inputs to the ground-truth builder behind the
     * secondary diagnostic, which compares them itself through its evidence and tier drift counts.
     * Recording a hash of something this run never opened would be provenance theatre.
     *
     * @param  array{model:string,configured_reasoning_effort:string,effective_reasoning_effort:string,prompt_variant:string,prompt_sha256:string}  $configuration
     * @param  array<string, mixed>  $priceSnapshot
     * @return array<string, mixed>
     */
    private function runManifest(
        OosParserEvaluationArm $arm,
        array $configuration,
        string $manifestHash,
        string $sourceKeyListHash,
        array $priceSnapshot,
    ): array {
        return [
            'format' => self::ManifestFormat,
            'version' => self::ManifestVersion,
            'evaluation_id' => $manifestHash,
            'arm' => $arm->name,
            'model' => $configuration['model'],
            'configured_reasoning_effort' => $configuration['configured_reasoning_effort'],
            'effective_reasoning_effort' => $configuration['effective_reasoning_effort'],
            /*
             * The variant is the declared intervention of a prompt arm, so the comparison exempts it
             * the way it exempts the model. The hash is exempted with it, and is what stops the
             * exemption becoming a hole: two arms may name different variants, but a variant whose
             * *text* was edited between them still moves the parser-surface fingerprint, which is
             * not exempt.
             */
            'prompt_variant' => $configuration['prompt_variant'],
            'prompt_sha256' => $configuration['prompt_sha256'],
            'ceilings' => [
                'max_completion_tokens' => config('service-tracking.email_parsing.max_completion_tokens'),
                'reasoning_token_headroom' => config('service-tracking.email_parsing.reasoning_token_headroom'),
                'extraction_attempts' => config('service-tracking.email_parsing.extraction_attempts'),
                'request_timeout_seconds' => config('openai.request_timeout'),
            ],
            /*
             * Load-bearing for the routing-safety guardrail: these two decide whether a plan is
             * auto-importable, so an arm run against a moved threshold would post a routing change
             * that has nothing to do with the model.
             */
            'thresholds' => [
                'review' => config('service-tracking.email_parsing.review_threshold'),
                'auto_import' => config('service-tracking.email_parsing.auto_import_threshold'),
            ],
            'service_tier' => config('openai.service_tier'),
            'inputs' => [
                'curation_manifest_hash' => $manifestHash,
                'source_key_list_hash' => $sourceKeyListHash,
                'price_snapshot_sha256' => CanonicalJson::hash($priceSnapshot),
            ],
            'price_snapshot' => $priceSnapshot,
            'parser_surface' => $this->parserSurface->fingerprint(),
            'application_commit' => RepositoryCommit::current(),
        ];
    }

    /**
     * @return array<string, int> the canonical tables and their row counts, every one of them zero
     */
    private function selectAndCertifyRehearsalConnection(): array
    {
        if ($this->productionGuard->guardsCurrentEnvironment()) {
            throw new RuntimeException('Refusing OoS parser evaluation: the current environment resolves the production database anchor.');
        }

        $expectedDatabase = config('database.connections.'.RehearsalDatabaseProvisioner::Connection.'.database');

        if (! is_string($expectedDatabase) || trim($expectedDatabase) === '') {
            throw new RuntimeException('Refusing OoS parser evaluation: no rehearsal database is configured.');
        }

        config(['database.default' => RehearsalDatabaseProvisioner::Connection]);
        $this->database->purge(RehearsalDatabaseProvisioner::Connection);
        $connection = $this->database->connection(RehearsalDatabaseProvisioner::Connection);

        if ($connection->getDatabaseName() !== $expectedDatabase) {
            throw new RuntimeException('Refusing OoS parser evaluation: the selected rehearsal connection resolved an unexpected database.');
        }

        if (! $connection->getSchemaBuilder()->hasTable('inbound_emails')) {
            throw new RuntimeException('Refusing OoS parser evaluation: the rehearsal database is not provisioned.');
        }

        /*
         * The parse is not a pure function of the email. `OosEmailParserService` reaches the
         * database through `ExistingEmailImportLookup` to ask whether a date already carries an
         * order imported from a different email, and that answer flows through date plausibility
         * into hold reasons, disposition and therefore routing category — the primary input to the
         * routing-safety guardrail.
         *
         * So the rehearsal database's contents are a hidden arm variable. Two arms run against
         * differently populated databases would differ for reasons that have nothing to do with the
         * model, and no later artifact could reveal it: the comparison checks curated inputs, not
         * database state. The provisioner already knows what clean means, so this asks it rather
         * than trusting that the operator reprovisioned between arms.
         */
        return $this->provisioner->certify(RehearsalDatabaseProvisioner::Connection);
    }

    private function inboundEmail(OosArchiveEntry $entry): InboundEmail
    {
        return new InboundEmail([
            'message_id' => $entry->syntheticMessageId,
            'from' => 'historic-oos@crockenhill.local',
            'subject' => $entry->subject,
            'body_plain' => $entry->bodyPlain,
            'received_at' => Carbon::instance($entry->syntheticReceivedAt),
        ]);
    }

    /**
     * @return array{projection:array<string,mixed>,telemetry:list<array<string,mixed>>}
     */
    private function parseEntry(OosArchiveEntry $entry): array
    {
        $this->telemetry->beginSource($entry->itemKey);

        try {
            $parse = $this->parser->parse($this->inboundEmail($entry));

            return [
                'projection' => $this->projectionRow($entry, $parse),
                'telemetry' => $this->telemetry->finishSource(),
            ];
        } catch (\Throwable $exception) {
            $this->telemetry->abandonSource();

            throw $exception;
        }
    }

    /**
     * The smallest live compatibility check that can still exercise the parser's long and
     * multi-service shapes before a corpus-wide spend. It deliberately parses the sources rather
     * than treating a fixture or a unit fake as account compatibility evidence.
     *
     * @param  list<OosArchiveEntry>  $entries
     * @return list<OosArchiveEntry>
     */
    private function canaries(array $entries): array
    {
        $full = array_values(array_filter($entries, static fn (OosArchiveEntry $entry): bool => $entry->assertsFullOrder()));

        if ($full === []) {
            throw new RuntimeException('The evaluation corpus has no full-scope source for a compatibility canary.');
        }

        usort($full, static fn (OosArchiveEntry $left, OosArchiveEntry $right): int => strlen($left->bodyPlain) <=> strlen($right->bodyPlain));
        $shortest = $full[0];
        $longest = $full[array_key_last($full)];
        $multiService = array_values(array_filter($entries, static fn (OosArchiveEntry $entry): bool => preg_match_all('/\\b(morning|evening)\\b/i', $entry->bodyPlain) >= 2));

        if ($multiService === []) {
            throw new RuntimeException('The evaluation corpus has no deterministic multi-service compatibility canary.');
        }

        $canaries = [$shortest, $longest, $multiService[0]];
        $unique = [];

        foreach ($canaries as $canary) {
            $unique[$canary->itemKey] = $canary;
        }

        return array_values($unique);
    }

    /**
     * Each sampled source is parsed twice and the two outputs compared **for equality**, not for
     * correctness: no labels exist at this point in the run, so a correctness-based rule would be
     * unsatisfiable.
     *
     * "Equality" is deliberately narrower than `raw_result_hash`, and is defined in exactly one
     * place — {@see OosParserExtractionSignature} — shared with the between-arm comparison. Two
     * earlier definitions were wrong in opposite directions. `raw_result_hash` covered `confidence`
     * (a continuous float) and the model's free-text validation reasoning, neither of which two
     * independent calls reproduce verbatim even when the extraction is stable, which inflated a real
     * baseline's self-disagreement to 100% on its first run. Narrowing it here by hand then left the
     * replacement comparing the model's emitted plan *order*, which the between-arm comparison does
     * not, so plan reordering counted as instability while not counting as discordance. Sharing the
     * definition is what makes this check a valid precondition rather than a second, drifting copy.
     *
     * Both replicates' telemetry is kept and tagged with its replicate number. This is the one place
     * an arm's retry behaviour is observable under repetition, and the retry rate is what carries
     * the truncation half of the latency guardrail — discarding it left the guardrail with only the
     * corpus's single-shot view.
     *
     * Every disagreeing pair is decomposed by field group and a bounded sample of the diffs is kept.
     * A rate on its own cannot say whether an unstable arm is moving items, titles, provenance or
     * routing, and that is the question §6.2 step 2's outcome now turns on.
     *
     * The sample is a prefix of a total order over the whole corpus, seeded on the manifest hash, so
     * it is both reproducible across arms and *nested* across sizes: drawing more sources extends
     * the sample rather than redrawing it. A run at a larger size therefore reports a figure that
     * remains directly comparable to every smaller banked run on the same manifest.
     *
     * @param  list<OosArchiveEntry>  $entries
     * @return array{requested_sample_size:int,sample_size:int,sample_source_keys:list<string>,self_disagreements:int,rate:float,field_decomposition:array<string,int>,retained_difference_limit:int,disagreements:list<array<string,mixed>>,validation:array<string,mixed>,telemetry:list<array<string,mixed>>}
     */
    private function stability(array $entries, string $evaluationId, int $sampleSize): array
    {
        usort($entries, static fn (OosArchiveEntry $left, OosArchiveEntry $right): int => hash('sha256', $evaluationId.$left->itemKey) <=> hash('sha256', $evaluationId.$right->itemKey));
        $sample = array_slice($entries, 0, min($sampleSize, count($entries)));
        $telemetry = [];
        $differences = [];

        $parses = [];

        foreach ($sample as $entry) {
            $first = $this->parseEntry($entry);
            $second = $this->parseEntry($entry);

            foreach ([1 => $first, 2 => $second] as $replicate => $parsed) {
                foreach ($parsed['telemetry'] as $call) {
                    $telemetry[] = ['replicate' => $replicate] + $call;
                }

                $parses[] = $parsed['projection'];
            }

            $firstSignature = $this->stabilitySignature($first['projection'], $entry->itemKey);
            $secondSignature = $this->stabilitySignature($second['projection'], $entry->itemKey);

            if (CanonicalJson::hash($firstSignature) === CanonicalJson::hash($secondSignature)) {
                continue;
            }

            $differences[] = $this->stabilityDifference($entry->itemKey, $firstSignature, $secondSignature);
        }

        return [
            /*
             * Both sizes, because a corpus smaller than the requested sample is silently the same
             * artifact as a deliberately smaller run — and "the corpus ran out" is a fact about how
             * much power the design could ever have had, not an operator's choice.
             */
            'requested_sample_size' => $sampleSize,
            'sample_size' => count($sample),
            'sample_source_keys' => array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $sample),
            'self_disagreements' => count($differences),
            // Cast: PHP's `/` returns an int on an even division, and the declared shape — which the
            // guardrail reads as a float — must not depend on whether the sample happened to divide.
            'rate' => $sample === [] ? 0.0 : (float) (count($differences) / count($sample)),
            'field_decomposition' => $this->fieldDecomposition($differences),
            /*
             * So a reader can tell a census of the disagreeing pairs from a truncated sample of
             * them without counting rows against the cap by hand. Round five of the evaluation had
             * to caveat its decomposition precisely because the artifact did not say.
             */
            'retained_difference_limit' => self::MaxRetainedStabilityDifferences,
            'disagreements' => array_slice($differences, 0, self::MaxRetainedStabilityDifferences),
            'validation' => $this->validationCensus($parses),
            'telemetry' => $telemetry,
        ];
    }

    /**
     * Which validator families actually fire, and what the correction call does about them.
     *
     * The attempt-level rule codes and correction outcomes live in each projection row's
     * `raw_result`, which a `--stability-only` run never writes: that mode returns before the corpus
     * loop and exports `canary` and `stability` alone. Without this the cheap screen could measure
     * routing flips and self-disagreement but not the first-pass failure profile — so a prompt
     * change aimed squarely at the validator's rules would be judged on everything except its target.
     *
     * The unit is a **parse**, not a source, and `parse_count` states it: the replicate parses every
     * sampled source twice, and both parses are real first passes. Reporting a rate over sources
     * would silently halve the denominator, and reporting one over *failing* parses would let the
     * outcome choose the population — the defect class recorded against verdict-class filtering.
     *
     * Codes rather than prose, because the prose is the operator-facing explanation and a reworded
     * message must not read as a changed failure family between two arms.
     *
     * @param  list<array<string, mixed>>  $parses
     * @return array<string, mixed>
     */
    private function validationCensus(array $parses): array
    {
        $firstPassFailures = 0;
        $codeCounts = ['content' => [], 'bookkeeping' => []];
        $corrected = 0;
        $correctionCodes = ['removed' => [], 'persisted' => [], 'introduced' => []];
        $outcomes = array_fill_keys(
            ['resolved', 'partially_resolved', 'unresolved', 'introduced_new_codes', 'changed_unrelated_fields'],
            0,
        );
        $correctionCallFailures = 0;
        $selectedAttempts = [];

        foreach ($parses as $projection) {
            /** @var array<string, mixed> $rawResult */
            $rawResult = $projection['raw_result'];
            /** @var list<array<string, mixed>> $attempts */
            $attempts = $rawResult['extraction_attempts'];

            foreach ($attempts as $attempt) {
                if (($attempt['selected'] ?? false) === true) {
                    $key = (string) ($attempt['attempt'] ?? 'unknown');
                    $selectedAttempts[$key] = ($selectedAttempts[$key] ?? 0) + 1;
                }
            }

            $firstPass = $attempts[0] ?? [];
            /** @var array{content:list<string>,bookkeeping:list<string>} $firstPassCodes */
            $firstPassCodes = $firstPass['validation_rule_codes'] ?? ['content' => [], 'bookkeeping' => []];

            if ($firstPassCodes['content'] !== [] || $firstPassCodes['bookkeeping'] !== []) {
                $firstPassFailures++;
            }

            foreach (['content', 'bookkeeping'] as $family) {
                foreach ($firstPassCodes[$family] as $code) {
                    $codeCounts[$family][$code] = ($codeCounts[$family][$code] ?? 0) + 1;
                }
            }

            $second = $attempts[1] ?? null;

            if ($second === null) {
                continue;
            }

            $corrected++;

            /*
             * A corrective call that threw records its error instead of a diagnosis. Counting it as
             * an unresolved correction would blame the validator families for a transport failure.
             */
            if (! isset($second['correction'])) {
                $correctionCallFailures++;

                continue;
            }

            /** @var array{removed_rule_codes:list<string>,persisted_rule_codes:list<string>,introduced_rule_codes:list<string>,changed_unrelated_fields:bool} $correction */
            $correction = $second['correction'];

            foreach (['removed', 'persisted', 'introduced'] as $outcome) {
                foreach ($correction["{$outcome}_rule_codes"] as $code) {
                    $correctionCodes[$outcome][$code] = ($correctionCodes[$outcome][$code] ?? 0) + 1;
                }
            }

            $outcomes[match (true) {
                $correction['persisted_rule_codes'] === [] && $correction['introduced_rule_codes'] === [] => 'resolved',
                $correction['removed_rule_codes'] === [] => 'unresolved',
                default => 'partially_resolved',
            }]++;

            if ($correction['introduced_rule_codes'] !== []) {
                $outcomes['introduced_new_codes']++;
            }

            if ($correction['changed_unrelated_fields'] === true) {
                $outcomes['changed_unrelated_fields']++;
            }
        }

        $rate = static fn (int $count): float => $parses === [] ? 0.0 : (float) ($count / count($parses));

        foreach ($codeCounts as &$family) {
            arsort($family);
        }

        unset($family);

        foreach ($correctionCodes as &$outcome) {
            arsort($outcome);
        }

        unset($outcome);

        ksort($selectedAttempts);

        return [
            'parse_count' => count($parses),
            'first_pass_failure_parses' => $firstPassFailures,
            'first_pass_failure_rate' => $rate($firstPassFailures),
            'first_pass_rule_codes' => $codeCounts,
            'corrected_parses' => $corrected,
            'correction_rate' => $rate($corrected),
            'correction_call_failures' => $correctionCallFailures,
            'correction_outcomes' => $outcomes,
            'correction_rule_codes' => $correctionCodes,
            'selected_attempt_counts' => $selectedAttempts,
        ];
    }

    /**
     * The projection row's content reduced to exactly what a between-arm comparison scores as
     * discordant — {@see OosParserExtractionSignature} — plus routing category.
     *
     * Both halves of that matter. Using the shared definition is what makes the within-arm check a
     * valid precondition for the between-arm one: a source can only self-disagree here if the same
     * change would count as discordant there. Routing category is added because §6.2 step 2 gates a
     * comparison whose primary counts routing-only discordance too.
     *
     * @param  array<string, mixed>  $projection
     * @return array{plans:array<string, array<string, mixed>>, routing_category:mixed}
     */
    private function stabilitySignature(array $projection, string $sourceKey): array
    {
        /** @var array<string, mixed> $rawResult */
        $rawResult = $projection['raw_result'];
        /** @var list<mixed> $plans */
        $plans = $rawResult['service_plans'];
        /** @var array<string, mixed> $routing */
        $routing = $projection['routing'];

        return [
            'plans' => OosParserExtractionSignature::fromPlanList($plans, "stability replicate for source {$sourceKey}"),
            'routing_category' => $routing['category'],
        ];
    }

    /**
     * @param  array{plans:array<string, array<string, mixed>>, routing_category:mixed}  $first
     * @param  array{plans:array<string, array<string, mixed>>, routing_category:mixed}  $second
     * @return array<string, mixed>
     */
    private function stabilityDifference(string $sourceKey, array $first, array $second): array
    {
        return [
            'source_key' => $sourceKey,
            'routing_category_differs' => $first['routing_category'] !== $second['routing_category'],
            'first_routing_category' => $first['routing_category'],
            'second_routing_category' => $second['routing_category'],
            'extraction' => OosParserExtractionSignature::fieldDifferences($first['plans'], $second['plans']),
        ];
    }

    /**
     * How many of the disagreeing pairs each field group is responsible for.
     *
     * A bare self-disagreement rate says an arm is unstable without saying in what, which is what
     * left §12 round three unable to choose between "the models genuinely differ this often", "the
     * signature is still too strict" and "`effort=none` is unusable for this task". A pair can count
     * against several groups; the columns are not a partition and are not expected to sum to the
     * disagreement count.
     *
     * @param  list<array<string, mixed>>  $differences
     * @return array<string, int>
     */
    private function fieldDecomposition(array $differences): array
    {
        $counts = array_fill_keys(['plan_keys', ...OosParserExtractionSignature::FieldGroups, 'routing_category'], 0);

        foreach ($differences as $difference) {
            /** @var array<string, mixed> $extraction */
            $extraction = $difference['extraction'];

            if ($extraction['plan_keys_differ'] === true) {
                $counts['plan_keys']++;
            }

            /** @var list<string> $groups */
            $groups = $extraction['groups_that_differ'];

            foreach ($groups as $group) {
                $counts[$group]++;
            }

            if ($difference['routing_category_differs'] === true) {
                $counts['routing_category']++;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function projectionRow(OosArchiveEntry $entry, OosEmailParseResult $parse): array
    {
        return [
            'source_key' => $entry->itemKey,
            'input_hash' => $entry->inputHash,
            'curation' => [
                'content_scope' => $entry->contentScope,
                'ground_truth_date' => $entry->groundTruthDate,
                'services_present' => $entry->servicesPresent,
            ],
            'routing' => $this->routing($parse),
            'raw_result_hash' => CanonicalJson::hash($this->rawResult($parse)),
            'raw_result' => $this->rawResult($parse),
        ];
    }

    /**
     * Which way the pipeline would have routed this source, decided by the plan objects themselves.
     *
     * A source is only as held as its most permissive plan: one auto-importable plan in a
     * two-service email means something imports unattended, whatever the other plan does.
     *
     * @return array{category:string,auto_importable_plan_keys:list<string>,importable_plan_keys:list<string>}
     */
    private function routing(OosEmailParseResult $parse): array
    {
        $autoImportable = array_values(array_filter(
            $parse->servicePlans,
            static fn (OosEmailServicePlan $plan): bool => $plan->isAutoImportable(),
        ));

        $importable = array_values(array_filter(
            $parse->servicePlans,
            static fn (OosEmailServicePlan $plan): bool => $plan->isManuallyImportable(),
        ));

        return [
            'category' => match (true) {
                $autoImportable !== [] => self::RoutingAutoImportable,
                $importable !== [] => self::RoutingReviewRequired,
                default => self::RoutingInvalidExtraction,
            },
            'auto_importable_plan_keys' => array_map(
                static fn (OosEmailServicePlan $plan): string => $plan->key(),
                $autoImportable,
            ),
            'importable_plan_keys' => array_map(
                static fn (OosEmailServicePlan $plan): string => $plan->key(),
                $importable,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function rawResult(OosEmailParseResult $parse): array
    {
        return [
            'date' => $parse->date,
            'service' => $parse->service?->value,
            'confidence' => $parse->confidenceScore,
            'needs_review' => $parse->needsReview,
            'should_import' => $parse->shouldImport,
            'disposition' => $parse->disposition->value,
            'validation_reasons' => $parse->validationReasons,
            'extraction_attempts' => $parse->extractionAttempts,
            'routing_gate_inputs' => [
                'review_threshold' => config('service-tracking.email_parsing.review_threshold'),
                'auto_import_threshold' => config('service-tracking.email_parsing.auto_import_threshold'),
                'consensus' => $parse->consensus,
                'adjudicated' => $parse->adjudicated,
                'plans' => array_map(static fn (OosEmailServicePlan $plan): array => [
                    'plan_key' => $plan->key(),
                    'service' => $plan->service?->value,
                    'date' => $plan->date,
                    'content_scope' => $plan->contentScope->value,
                    'item_count' => count($plan->items),
                    'confidence' => $plan->confidence,
                    'content_validation_reasons' => $plan->contentValidationReasons,
                    'hold_reasons' => $plan->holdReasonValues(),
                    'disposition' => $plan->disposition->value,
                ], $parse->servicePlans),
            ],
            'service_plans' => array_map(
                static fn ($plan): array => $plan->toMetadataArray(),
                $parse->servicePlans,
            ),
        ];
    }
}

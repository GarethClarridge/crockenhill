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
     * Bumped to 2 when each source gained its `routing` block.
     *
     * The routing-safety guardrail asks whether the candidate became auto-importable where the
     * baseline was held, so the comparison needs the routing category the pipeline would actually
     * have taken. Version 1 carried only the raw disposition strings, from which routing can be
     * *recomputed* — and a recomputation is a second copy of the rules that silently stops matching
     * the first. The plan objects decide it here, once, and the projection records the answer.
     */
    private const ProjectionVersion = 2;

    /** The pipeline's three routing outcomes for a source, most permissive first. */
    private const RoutingAutoImportable = 'auto_importable';

    private const RoutingReviewRequired = 'review_required';

    private const RoutingInvalidExtraction = 'invalid_extraction';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly HistoricImportProductionGuard $productionGuard,
        private readonly OosEmailParserService $parser,
        private readonly OosParserEvaluationTelemetry $telemetry,
    ) {}

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @return array<string, mixed>
     */
    public function run(OosParserEvaluationArm $arm, array $entries, string $manifestHash, string $manifestPath): array
    {
        $arm->apply();
        $configuration = $arm->resolvedConfiguration();
        $this->selectAndCertifyRehearsalConnection();
        $this->telemetry->beginRun();

        $sourceKeys = array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $entries);

        if (count($sourceKeys) !== count(array_unique($sourceKeys))) {
            throw new RuntimeException('The evaluation source set contains duplicate source keys.');
        }

        $canaries = $this->canaries($entries);

        foreach ($canaries as $entry) {
            $canary = $this->parseEntry($entry);

            if (array_filter($canary['telemetry'], static fn (array $call): bool => $call['usage_missing'] === true) !== []) {
                throw new RuntimeException("Compatibility canary {$entry->itemKey} returned no usage telemetry.");
            }
        }

        $stability = $this->stability($entries, $manifestHash);
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
            'returned_model' => $this->telemetry->returnedModel(),
            'database_connection' => RehearsalDatabaseProvisioner::Connection,
            'database_name' => (string) $this->database->connection()->getDatabaseName(),
            'manifest_path' => basename($manifestPath),
            'manifest_hash' => $manifestHash,
            'source_count' => count($sourceKeys),
            'source_key_list_hash' => CanonicalJson::hash($sourceKeys),
            'canary_source_keys' => array_map(static fn (OosArchiveEntry $entry): string => $entry->itemKey, $canaries),
            'stability' => $stability,
            'raw_results' => $results,
        ];
    }

    private function selectAndCertifyRehearsalConnection(): void
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
     * @param  list<OosArchiveEntry>  $entries
     * @return array{sample_size:int,self_disagreements:int,rate:float}
     */
    private function stability(array $entries, string $evaluationId): array
    {
        usort($entries, static fn (OosArchiveEntry $left, OosArchiveEntry $right): int => hash('sha256', $evaluationId.$left->itemKey) <=> hash('sha256', $evaluationId.$right->itemKey));
        $sample = array_slice($entries, 0, min(30, count($entries)));
        $disagreements = 0;

        foreach ($sample as $entry) {
            $first = $this->parseEntry($entry)['projection']['raw_result_hash'];
            $second = $this->parseEntry($entry)['projection']['raw_result_hash'];

            if ($first !== $second) {
                $disagreements++;
            }
        }

        return [
            'sample_size' => count($sample),
            'self_disagreements' => $disagreements,
            'rate' => $sample === [] ? 0.0 : $disagreements / count($sample),
        ];
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
            'service_plans' => array_map(
                static fn ($plan): array => $plan->toMetadataArray(),
                $parse->servicePlans,
            ),
        ];
    }
}

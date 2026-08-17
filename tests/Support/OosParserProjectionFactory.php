<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\CanonicalJson;

/**
 * Builds raw-result projections in the shape `OosParserArmRunner` exports, so the primary
 * comparison can be tested without a live arm.
 *
 * The factory computes the source-key list hash from the rows it is given rather than accepting
 * one, because the comparison checks that a projection reproduces its own binding — a fixture that
 * could hand over a mismatched pair would test the check with a value the runner can never produce.
 */
class OosParserProjectionFactory
{
    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function projection(string $arm, string $model, array $sources, array $overrides = []): array
    {
        $sourceKeys = array_map(static fn (array $source): string => (string) $source['source_key'], $sources);

        $canarySource = $sourceKeys === [] ? 'canary' : $sourceKeys[0];

        return array_replace([
            'format' => 'crockenhill-oos-parser-raw-projection',
            'version' => 3,
            'arm' => $arm,
            'model' => $model,
            'configured_reasoning_effort' => 'none',
            'effective_reasoning_effort' => 'none',
            'returned_model' => $model,
            'database_connection' => 'rehearsal',
            'database_name' => 'crockenhill_rehearsal',
            'rehearsal_certification' => [
                'church_services' => 0,
                'church_service_items' => 0,
                'church_service_source_records' => 0,
                'sermons' => 0,
                'inbound_emails' => 0,
            ],
            'manifest_path' => 'oos-curation-manifest.json',
            'manifest_hash' => 'manifest-hash',
            'source_count' => count($sources),
            'source_key_list_hash' => CanonicalJson::hash($sourceKeys),
            'canary' => [
                'source_keys' => [$canarySource],
                'telemetry' => [self::call($canarySource, $model)],
            ],
            'stability' => [
                'sample_size' => 30,
                'self_disagreements' => 0,
                'rate' => 0.0,
                'telemetry' => [
                    ['replicate' => 1] + self::call($canarySource, $model),
                    ['replicate' => 2] + self::call($canarySource, $model),
                ],
            ],
            'raw_results' => $sources,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function source(string $sourceKey, string $model, array $plans, array $overrides = []): array
    {
        $autoImportable = array_values(array_map(
            static fn (array $plan): string => (string) $plan['plan_key'],
            array_filter($plans, static fn (array $plan): bool => $plan['disposition'] === 'auto_importable'),
        ));

        $importable = array_values(array_map(
            static fn (array $plan): string => (string) $plan['plan_key'],
            array_filter($plans, static fn (array $plan): bool => $plan['disposition'] !== 'invalid_extraction'),
        ));

        $rawResult = [
            'date' => $plans === [] ? null : $plans[0]['date'],
            'service' => $plans === [] ? null : $plans[0]['service'],
            'confidence' => 0.9,
            'needs_review' => false,
            'should_import' => true,
            'disposition' => match (true) {
                $autoImportable !== [] => 'auto_importable',
                $importable !== [] => 'review_required',
                default => 'invalid_extraction',
            },
            'validation_reasons' => [],
            'service_plans' => $plans,
        ];

        return array_replace([
            'source_key' => $sourceKey,
            'input_hash' => "input-{$sourceKey}",
            'curation' => [
                'content_scope' => 'full',
                'ground_truth_date' => '2023-01-01',
                'services_present' => ['morning'],
            ],
            'routing' => [
                'category' => match (true) {
                    $autoImportable !== [] => 'auto_importable',
                    $importable !== [] => 'review_required',
                    default => 'invalid_extraction',
                },
                'auto_importable_plan_keys' => $autoImportable,
                'importable_plan_keys' => $importable,
            ],
            'raw_result_hash' => CanonicalJson::hash($rawResult),
            'raw_result' => $rawResult,
            'telemetry' => [self::call($sourceKey, $model)],
        ], $overrides);
    }

    /**
     * @param  list<string>  $itemTitles
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function plan(string $service, string $date, array $itemTitles, array $overrides = []): array
    {
        $items = [];
        $bindings = [];

        foreach (array_values($itemTitles) as $index => $title) {
            $items[] = [
                'position' => $index + 1,
                'type' => 'songs',
                'section_type' => 'song',
                'title' => $title,
                'source_title' => $title,
                'openlp_search_title' => null,
                'metadata' => null,
            ];

            $bindings[] = ['position' => $index + 1, 'source_line_ids' => [$index + 2], 'continuation' => false];
        }

        return array_replace([
            'plan_key' => "{$service}:{$date}",
            'service' => $service,
            'date' => $date,
            'content_scope' => 'full',
            'items' => $items,
            'confidence' => 0.9,
            'needs_review' => false,
            'should_import' => true,
            'disposition' => 'auto_importable',
            'validation_reasons' => [],
            'content_validation_reasons' => [],
            'hold_reasons' => [],
            'source_provenance' => [
                'plan_index' => 0,
                'rejected_service' => null,
                'service_evidence_line_ids' => [1],
                'structural_findings' => [],
                'items' => $bindings,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function call(string $sourceKey, string $model, array $overrides = []): array
    {
        return array_replace([
            'source_key' => $sourceKey,
            'call_role' => 'primary',
            'attempt' => 1,
            'response_model' => $model,
            'latency_ms' => 1000,
            'usage_missing' => false,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'total_tokens' => 1200],
        ], $overrides);
    }

    /** @return array<string, array{input: float, output: float}> */
    public static function prices(): array
    {
        return [
            'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
            'gpt-5.6-luna' => ['input' => 0.20, 'output' => 1.20],
        ];
    }
}

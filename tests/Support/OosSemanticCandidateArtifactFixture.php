<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosSemanticCandidateEvidenceRunner;
use App\Support\CanonicalJson;

/**
 * A one-source adjudicated corpus and a matching banked candidate evidence artifact, shared by the
 * recompilation unit and command tests so both replay the same artifact shape.
 *
 * The banked compilation deliberately claims `full` for a plan of nothing but songs — what the
 * compiler produced before the structural-frame content-scope rule — so a recompilation has
 * something real to change.
 */
class OosSemanticCandidateArtifactFixture
{
    public function source(): OosEmailSourceDocument
    {
        return OosEmailSourceDocument::fromContext('Hymns for Sunday 23 August 2026', "Morning Service\nHymn 100 Praise the Lord", '2026-08-19');
    }

    /** @return array<string, mixed> */
    public function corpus(): array
    {
        $source = $this->source();
        $corpus = [
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'version' => FreezeOosSemanticEvaluationCorpus::Version,
            'completeness' => ['scoreable' => true, 'fully_adjudicated_sources' => 1, 'pending_sources' => 0],
            'sources' => [[
                'item_key' => 'sample',
                'source_document' => [
                    'input_hash' => $source->inputHash(),
                    'subject' => $source->subject,
                    'received_date' => $source->receivedDate,
                    'lines' => $source->semanticLineRecords(),
                ],
                'truth' => [
                    'adjudication_state' => 'adjudicated',
                    'services' => [],
                    'annotations' => [],
                    'expected_plans' => [],
                    'adjudicated_by' => 'maintainer@example.test',
                    'adjudicated_at' => '2026-08-19T12:00:00+01:00',
                ],
            ]],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }

    /**
     * @param  null|callable(array<string, mixed>): array<string, mixed>  $mutator
     * @return array<string, mixed>
     */
    public function candidate(?callable $mutator = null): array
    {
        $source = $this->source();
        $annotations = [
            'format_version' => 1,
            'services' => [[
                'group_id' => 'morning',
                'proposed_service' => 'morning',
                'boundary_line_ids' => [1],
                'uncertainties' => [],
            ]],
            'annotations' => [
                1 => $this->annotation(1, 'service_boundary', 'morning'),
                2 => $this->annotation(2, 'item', 'morning', 'song'),
            ],
            'telemetry' => [],
        ];
        // What the pre-rule compiler produced for this annotation: a songs-only plan claiming to be
        // the service's complete running order.
        $compilation = [
            'extraction' => [
                'items' => [['type' => 'song', 'title' => 'Hymn 100 Praise the Lord', 'source_line_ids' => [2], 'continuation' => false, 'semantic_kind' => 'song']],
                'confidence' => 0.75,
                'notes' => [],
                'services' => [[
                    'service' => 'morning',
                    'date' => '2026-08-23',
                    'content_scope' => 'full',
                    'service_evidence_line_ids' => [1],
                    'items' => [['type' => 'song', 'title' => 'Hymn 100 Praise the Lord', 'source_line_ids' => [2], 'continuation' => false, 'semantic_kind' => 'song']],
                    'confidence' => 0.75,
                ]],
                'service_count' => 1,
                'ignored_lines' => [],
                'provenance_complete' => true,
            ],
            'risk_signals' => [],
        ];
        $candidate = [
            'format' => OosSemanticCandidateEvidenceRunner::Format,
            'version' => OosSemanticCandidateEvidenceRunner::Version,
            'candidate' => [
                'configured_model' => 'gpt-5.6-terra',
                'returned_model' => 'gpt-5.6-terra-2026-08-01',
                'parser_surface' => ['hash' => 'a-stale-surface-hash', 'files' => []],
            ],
            'inputs' => [
                'corpus_hash' => 'a-stale-corpus-hash',
                'price_snapshot_sha256' => str_repeat('a', 64),
                'application_commit' => 'c4455b897',
            ],
            'usage' => ['calls' => 1, 'input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120, 'latency_ms' => 125],
            'calls' => [['item_key' => 'sample', 'role' => 'annotation', 'returned_model' => 'gpt-5.6-terra-2026-08-01']],
            'results' => [[
                'item_key' => 'sample',
                'source_hash' => $source->inputHash(),
                'extraction' => $compilation['extraction'],
                'risk_signals' => [],
                'attempts' => [[
                    'attempt' => 1,
                    'selected' => true,
                    'parser' => 'semantic_annotations',
                    'source_hash' => $source->inputHash(),
                    'parser_surface_hash' => 'a-stale-surface-hash',
                    'prompt_hash' => str_repeat('b', 64),
                    'schema_hash' => str_repeat('c', 64),
                    'initial_annotations' => $annotations,
                    'initial_rule_codes' => [],
                    'allowed_patch' => [],
                    'patch' => null,
                    'repair_telemetry' => null,
                    'final_annotations' => $annotations,
                    'final_rule_codes' => [],
                    'repair_error' => null,
                    'compilation' => $compilation,
                    'compilation_hash' => CanonicalJson::hash($compilation),
                ]],
            ]],
        ];

        if ($mutator !== null) {
            $candidate = $mutator($candidate);
        }

        $candidate['evidence_hash'] = CanonicalJson::hash($candidate);

        return $candidate;
    }

    /** @return array<string, mixed> */
    public function annotation(int $lineId, string $role, ?string $group = null, ?string $kind = null): array
    {
        return [
            'line_id' => $lineId,
            'role' => $role,
            'service_group_id' => $group,
            'item_kind' => $kind,
            'continuation_target_line_id' => null,
            'uncertainty' => null,
            'shared_service_group_ids' => [],
            'boundary_also_item' => false,
        ];
    }
}

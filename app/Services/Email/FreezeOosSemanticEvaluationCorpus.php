<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailSourceDocument;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * Builds the private, hash-bound semantic-parser evaluation worksheet.
 *
 * Legacy extraction is retained only as a machine prefill. It is never labelled as truth: every
 * source remains unscoreable until its semantic annotations and expected plans are adjudicated.
 */
class FreezeOosSemanticEvaluationCorpus
{
    public const string Format = 'crockenhill-oos-semantic-evaluation-corpus';

    public const int Version = 1;

    public const string TruthFormat = 'crockenhill-oos-semantic-line-truth';

    public const int TruthVersion = 1;

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @param  array<string, mixed>  $legacyProjection
     * @param  array<string, mixed>  $stabilityDiagnostic
     * @param  array<string, mixed>  $itemTruth
     * @param  list<string>  $hardCaseKeys
     * @return array<string, mixed>
     */
    public function build(
        array $entries,
        array $legacyProjection,
        array $stabilityDiagnostic,
        array $itemTruth,
        array $hardCaseKeys = [],
    ): array {
        $manifestHash = $this->sharedManifestHash($legacyProjection, $stabilityDiagnostic);
        $entriesByKey = $this->entriesByKey($entries);
        $legacyByKey = $this->legacyByKey($legacyProjection);
        $sampleKeys = $this->sampleKeys($stabilityDiagnostic);
        $selectedKeys = array_values(array_unique([...$sampleKeys, ...$hardCaseKeys]));
        $truthByIdentity = $this->truthByIdentity($itemTruth);
        $sources = [];

        foreach ($selectedKeys as $itemKey) {
            $entry = $entriesByKey[$itemKey] ?? throw new RuntimeException(
                "Selected semantic evaluation source {$itemKey} is not an approved corpus entry."
            );
            $legacy = $legacyByKey[$itemKey] ?? throw new RuntimeException(
                "Legacy projection has no result for selected semantic evaluation source {$itemKey}."
            );
            $source = OosEmailSourceDocument::fromContext(
                $entry->subject,
                $entry->bodyPlain,
                $entry->syntheticReceivedAt->toDateString(),
            );
            $identity = $entry->groundTruthDate.'|'.$entry->servicesPresent[0];

            $sources[] = [
                'item_key' => $itemKey,
                'selection' => [
                    'stability_sample' => in_array($itemKey, $sampleKeys, true),
                    'hard_case' => in_array($itemKey, $hardCaseKeys, true),
                ],
                'approved_payload_sha256' => $entry->inputHash,
                'source_document' => [
                    'format_version' => OosEmailSourceDocument::PortableFormatVersion,
                    'input_hash' => $source->inputHash(),
                    'subject' => $source->subject,
                    'received_date' => $source->receivedDate,
                    'lines' => $source->semanticLineRecords(),
                ],
                'authority' => [
                    'date' => $entry->groundTruthDate,
                    'service' => $entry->servicesPresent[0],
                    'content_scope' => $entry->contentScope,
                    'item_truth' => $truthByIdentity[$identity] ?? null,
                ],
                'truth' => [
                    'format' => self::TruthFormat,
                    'version' => self::TruthVersion,
                    'adjudication_state' => 'pending',
                    'services' => null,
                    'annotations' => null,
                    'expected_plans' => null,
                    'adjudicated_by' => null,
                    'adjudicated_at' => null,
                ],
                'legacy_machine_prefill' => [
                    'not_truth' => true,
                    'routing' => $legacy['routing'] ?? null,
                    'output' => $legacy['raw_result'] ?? null,
                    'telemetry' => $legacy['telemetry'] ?? null,
                    'semantic_worksheet' => $this->legacySemanticPrefill($source, $legacy),
                ],
            ];
        }

        $artifact = [
            'format' => self::Format,
            'version' => self::Version,
            'private' => true,
            'inputs' => [
                'curation_manifest_hash' => $manifestHash,
                'approved_source_count' => count($entries),
                'legacy_projection_sha256' => CanonicalJson::hash($legacyProjection),
                'stability_diagnostic_sha256' => CanonicalJson::hash($stabilityDiagnostic),
                'item_truth_sha256' => CanonicalJson::hash($itemTruth),
            ],
            'selection' => [
                'stability_sample_source_keys' => $sampleKeys,
                'hard_case_source_keys' => array_values(array_unique($hardCaseKeys)),
                'selected_source_keys' => $selectedKeys,
            ],
            'completeness' => [
                'source_count' => count($sources),
                'fully_adjudicated_sources' => 0,
                'pending_sources' => count($sources),
                'scoreable' => false,
                'blocking_fields' => ['truth.services', 'truth.annotations', 'truth.expected_plans'],
            ],
            'sources' => $sources,
        ];
        $artifact['corpus_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $legacyProjection
     * @param  array<string, mixed>  $stabilityDiagnostic
     */
    private function sharedManifestHash(array $legacyProjection, array $stabilityDiagnostic): string
    {
        $legacy = $legacyProjection['manifest_hash'] ?? null;
        $stability = $stabilityDiagnostic['manifest_hash'] ?? null;

        if (! is_string($legacy) || ! is_string($stability) || ! hash_equals($legacy, $stability)) {
            throw new RuntimeException('Legacy projection and stability diagnostic do not share one curation manifest hash.');
        }

        return $legacy;
    }

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @return array<string, OosArchiveEntry>
     */
    private function entriesByKey(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            if (isset($indexed[$entry->itemKey])) {
                throw new RuntimeException("Approved corpus contains duplicate source key {$entry->itemKey}.");
            }

            $indexed[$entry->itemKey] = $entry;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, array<string, mixed>>
     */
    private function legacyByKey(array $projection): array
    {
        $rows = $projection['raw_results'] ?? null;

        if (! is_array($rows)) {
            throw new RuntimeException('Legacy projection has no raw_results list.');
        }

        $indexed = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['source_key'] ?? null)) {
                throw new RuntimeException('Legacy projection contains a result without a source key.');
            }

            $indexed[$row['source_key']] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return list<string>
     */
    private function sampleKeys(array $diagnostic): array
    {
        $keys = $diagnostic['stability']['sample_source_keys'] ?? null;

        if (! is_array($keys) || $keys === []) {
            throw new RuntimeException('Stability diagnostic has no deterministic sample source keys.');
        }

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException('Stability diagnostic contains an invalid sample source key.');
            }
        }

        return array_values($keys);
    }

    /**
     * @param  array<string, mixed>  $itemTruth
     * @return array<string, array<string, mixed>>
     */
    private function truthByIdentity(array $itemTruth): array
    {
        if (($itemTruth['format'] ?? null) !== 'historic-item-ground-truth') {
            throw new RuntimeException('Item truth is not a historic-item-ground-truth artifact.');
        }

        $identities = $itemTruth['identities'] ?? null;

        if (! is_array($identities)) {
            throw new RuntimeException('Item truth has no identities list.');
        }

        $indexed = [];

        foreach ($identities as $identity) {
            if (! is_array($identity)
                || ! is_string($identity['date'] ?? null)
                || ! is_string($identity['service'] ?? null)) {
                throw new RuntimeException('Item truth contains an invalid identity.');
            }

            $indexed[$identity['date'].'|'.$identity['service']] = $identity;
        }

        return $indexed;
    }

    /**
     * Translate retained legacy provenance into editable semantic fields without calling it truth.
     *
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function legacySemanticPrefill(OosEmailSourceDocument $source, array $legacy): array
    {
        $plans = $legacy['raw_result']['service_plans'] ?? [];
        $services = [];
        $annotations = [];

        foreach ($source->lineIds() as $lineId) {
            $annotations[$lineId] = [
                'line_id' => $lineId,
                'role' => null,
                'service_group_id' => null,
                'item_kind' => null,
                'continuation_target_line_id' => null,
                'uncertainty' => null,
                'shared_service_group_ids' => [],
                'boundary_also_item' => false,
            ];
        }

        if (! is_array($plans)) {
            $plans = [];
        }

        foreach (array_values($plans) as $index => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $groupId = 'legacy_plan_'.($index + 1);
            $provenance = is_array($plan['source_provenance'] ?? null) ? $plan['source_provenance'] : [];
            $boundaries = $this->integerList($provenance['service_evidence_line_ids'] ?? []);
            $services[] = [
                'group_id' => $groupId,
                'proposed_service' => is_string($plan['service'] ?? null) ? $plan['service'] : null,
                'boundary_line_ids' => $boundaries,
                'uncertainties' => [],
            ];

            foreach ($boundaries as $lineId) {
                if (isset($annotations[$lineId])) {
                    $annotations[$lineId]['role'] = 'service_boundary';
                    $annotations[$lineId]['service_group_id'] = $groupId;
                }
            }

            $items = is_array($plan['items'] ?? null) ? $plan['items'] : [];
            $itemsByPosition = [];

            foreach ($items as $item) {
                if (is_array($item) && is_int($item['position'] ?? null)) {
                    $itemsByPosition[$item['position']] = $item;
                }
            }

            $itemProvenance = is_array($provenance['items'] ?? null) ? $provenance['items'] : [];

            foreach ($itemProvenance as $itemEvidence) {
                if (! is_array($itemEvidence) || ! is_int($itemEvidence['position'] ?? null)) {
                    continue;
                }

                $lineIds = $this->integerList($itemEvidence['source_line_ids'] ?? []);
                $firstLineId = $lineIds[0] ?? null;
                $item = $itemsByPosition[$itemEvidence['position']] ?? [];

                foreach ($lineIds as $offset => $lineId) {
                    if (! isset($annotations[$lineId])) {
                        continue;
                    }

                    if ($offset === 0) {
                        if ($annotations[$lineId]['role'] === 'service_boundary') {
                            $annotations[$lineId]['boundary_also_item'] = true;
                        } else {
                            $annotations[$lineId]['role'] = 'item';
                        }

                        $annotations[$lineId]['service_group_id'] = $groupId;
                        $annotations[$lineId]['item_kind'] = $this->legacyItemKind($item);
                    } else {
                        $annotations[$lineId]['role'] = 'continuation';
                        $annotations[$lineId]['service_group_id'] = $groupId;
                        $annotations[$lineId]['continuation_target_line_id'] = $firstLineId;
                    }
                }
            }
        }

        $unresolvedLineIds = [];

        foreach ($annotations as $lineId => $annotation) {
            if ($annotation['role'] === null) {
                $unresolvedLineIds[] = $lineId;
            }
        }

        return [
            'services' => $services,
            'annotations' => array_values($annotations),
            'unresolved_line_ids' => $unresolvedLineIds,
            'prefilled_line_count' => count($annotations) - count($unresolvedLineIds),
            'source_line_count' => count($annotations),
        ];
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_int(...)));
    }

    /** @param array<string, mixed> $item */
    private function legacyItemKind(array $item): string
    {
        $sectionType = $item['section_type'] ?? null;

        return match ($sectionType) {
            'welcome', 'prayer', 'notices', 'song', 'childrens_talk', 'bible_reading', 'sermon' => $sectionType,
            default => 'other',
        };
    }
}

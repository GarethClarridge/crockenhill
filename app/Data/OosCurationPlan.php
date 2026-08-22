<?php

declare(strict_types=1);

namespace App\Data;

/**
 * @phpstan-type OosCurationInclude array{
 *     item_key:string,
 *     source_kind:string,
 *     relative_path:string,
 *     sha256:string,
 *     byte_size:int,
 *     payload:string,
 *     verbatim_relative_path:?string,
 *     formatted_relative_path:?string,
 *     source_date:?string,
 *     resolved_date:string,
 *     resolved_service:string,
 *     additional_services:list<string>,
 *     additional_service_labels:array<string, string>,
 *     curation_note:?string,
 *     service_label:?string,
 *     title_override:?string,
 *     date_decision:string,
 *     date_decision_reason:?string,
 *     content_scope:string,
 *     partial_scope_reason:?string,
 *     supersedes:?string,
 *     parse_decision:string,
 *     expected_item_count:?int,
 *     decided_by:?string,
 *     decided_at:?string,
 *     decision_rule_version:?string
 * }
 */
readonly class OosCurationPlan
{
    /**
     * @param  list<OosCurationInclude>  $includes
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $manifestHash,
        public string $planHash,
        public array $includes,
        public array $counts,
        public string $batchKey,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'format' => 'crockenhill-oos-import-plan',
            'version' => 1,
            'batch_key' => $this->batchKey,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'counts' => $this->counts,
            'includes' => $this->includes,
        ];
    }
}

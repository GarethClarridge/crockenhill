<?php

declare(strict_types=1);

namespace App\Data;

/**
 * @phpstan-type OpenLpCurationInclude array{
 *     item_key:string,
 *     source_kind:string,
 *     relative_path:string,
 *     sha256:string,
 *     byte_size:int,
 *     logical_upload_filename:string,
 *     resolved_date:string,
 *     resolved_service:string,
 *     alias_reason:?string,
 *     parse_decision:string,
 *     concatenation_decision:string,
 *     expected_item_count:int,
 *     decided_by:?string,
 *     decided_at:?string,
 *     decision_rule_version:?string
 * }
 */
readonly class OpenLpCurationPlan
{
    /**
     * @param  list<OpenLpCurationInclude>  $includes
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
            'format' => 'crockenhill-openlp-import-plan',
            'version' => 2,
            'batch_key' => $this->batchKey,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'counts' => $this->counts,
            'includes' => $this->includes,
        ];
    }
}

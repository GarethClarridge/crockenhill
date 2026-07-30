<?php

declare(strict_types=1);

namespace App\Data;

readonly class OpenLpCurationPlan
{
    /**
     * @param  list<array{
     *     relative_path:string,
     *     sha256:string,
     *     logical_upload_filename:string,
     *     resolved_date:string,
     *     resolved_service:string,
     *     alias_reason:?string
     * }>  $includes
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $manifestHash,
        public string $planHash,
        public array $includes,
        public array $counts,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'format' => 'crockenhill-openlp-import-plan',
            'version' => 1,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'counts' => $this->counts,
            'includes' => $this->includes,
        ];
    }
}

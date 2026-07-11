<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonService;

readonly class OosEmailParseResult
{
    /**
     * The `date`, `service`, `items`, `confidenceScore`, `needsReview` and `shouldImport`
     * fields describe the primary (morning-first) plan and are retained for stored-metadata
     * compatibility and inbox display. Multi-service imports read `servicePlans`.
     *
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  array<string, mixed>  $importMetadata
     * @param  list<OosEmailServicePlan>  $servicePlans
     */
    public function __construct(
        public ?string $date,
        public ?SermonService $service,
        public array $items,
        public float $confidenceScore,
        public bool $needsReview,
        public bool $shouldImport,
        public array $importMetadata,
        public array $servicePlans = [],
        public bool $isLegacyFlattened = false,
    ) {}

    /**
     * @return list<OosEmailServicePlan>
     */
    public function importablePlans(): array
    {
        return array_values(array_filter(
            $this->servicePlans,
            static fn (OosEmailServicePlan $plan): bool => $plan->isImportable(),
        ));
    }

    public function planByKey(string $key): ?OosEmailServicePlan
    {
        foreach ($this->servicePlans as $plan) {
            if ($plan->key() === $key) {
                return $plan;
            }
        }

        return null;
    }
}

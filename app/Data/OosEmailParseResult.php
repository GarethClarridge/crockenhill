<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Services\Email\OosEmailExtractionValidator;

readonly class OosEmailParseResult
{
    /**
     * The `date`, `service`, `items`, `confidenceScore`, `needsReview` and `shouldImport`
     * fields describe the primary (morning-first) plan and are retained for stored-metadata
     * compatibility and inbox display. Multi-service imports read `servicePlans`.
     *
     * `consensus` means two *independent* attempts produced the same order and is a gate input:
     * it lets a plan above the review threshold import unattended. `adjudicated` means a third
     * call was shown both candidates and asked to resolve them, which is weaker evidence and is
     * deliberately not a gate input — an adjudicated plan stays held (HIR-D6, 2026-08-14). Never
     * collapse the two flags.
     *
     * `ignoredLines` is the extractor's declaration that it *saw* a source line and chose not to
     * extract from it. It is not decoration: {@see OosEmailExtractionValidator}
     * requires every source line to be classified as service evidence, an item, or ignored
     * context, so a parse that loses its ignored lines cannot be re-validated — the check fails
     * open into "unaccounted line" for every greeting and signature in the document. It is
     * therefore carried through every rebuild of this object, exactly like `extractionAttempts`.
     *
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  array<string, mixed>  $importMetadata
     * @param  list<OosEmailServicePlan>  $servicePlans
     * @param  list<string>  $validationReasons
     * @param  list<array<string, mixed>>  $extractionAttempts
     * @param  list<array{line_id:int,reason:string}>  $ignoredLines
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
        public OosEmailParseDisposition $disposition = OosEmailParseDisposition::AutoImportable,
        public array $validationReasons = [],
        public array $extractionAttempts = [],
        public bool $consensus = false,
        public bool $adjudicated = false,
        public array $ignoredLines = [],
    ) {}

    /**
     * @return list<OosEmailServicePlan>
     */
    public function importablePlans(): array
    {
        return array_values(array_filter(
            $this->servicePlans,
            static fn (OosEmailServicePlan $plan): bool => $plan->isManuallyImportable(),
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

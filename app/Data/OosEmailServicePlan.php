<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;

/**
 * A single service order extracted from an inbound OoS email. One email routinely carries
 * both a morning and an evening plan (and occasionally a special), each with its own date,
 * ordered items and confidence.
 */
readonly class OosEmailServicePlan
{
    /**
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  list<string>  $validationReasons
     * @param  array<string, mixed>  $sourceProvenance
     */
    public function __construct(
        public ?SermonService $service,
        public ?string $date,
        public array $items,
        public float $confidence,
        public bool $needsReview,
        public bool $shouldImport,
        public OosEmailParseDisposition $disposition = OosEmailParseDisposition::AutoImportable,
        public array $validationReasons = [],
        public array $sourceProvenance = [],
    ) {}

    /**
     * Stable identifier for a plan within a single parse result, used to address a plan
     * across the review route and Livewire actions (e.g. "morning:2026-07-12").
     */
    public function key(): string
    {
        $service = $this->service instanceof SermonService ? $this->service->value : 'unknown';

        return $service.':'.($this->date ?? 'unknown');
    }

    /**
     * A plan can be created/merged into a service only when it has the three fields the
     * importer needs. Confidence is a separate, contextual gate handled by callers.
     */
    public function isImportable(): bool
    {
        return $this->service instanceof SermonService
            && is_string($this->date)
            && $this->date !== ''
            && $this->items !== [];
    }

    public function isAutoImportable(): bool
    {
        return $this->disposition === OosEmailParseDisposition::AutoImportable
            && $this->isImportable();
    }

    public function isManuallyImportable(): bool
    {
        return $this->disposition !== OosEmailParseDisposition::InvalidExtraction
            && $this->isImportable();
    }
}

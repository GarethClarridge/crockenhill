<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
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
     * @param  list<string>  $contentValidationReasons
     * @param  list<OosEmailPlanHoldReason>  $holdReasons
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
        public array $contentValidationReasons = [],
        public array $holdReasons = [],
        public array $sourceProvenance = [],
        public OosEmailContentScope $contentScope = OosEmailContentScope::Full,
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
            && $this->contentScope !== OosEmailContentScope::Unknown
            && $this->isImportable();
    }

    public function isManuallyImportable(): bool
    {
        return $this->disposition !== OosEmailParseDisposition::InvalidExtraction
            && $this->isImportable();
    }

    public function withContentScope(OosEmailContentScope $contentScope): self
    {
        return new self(
            service: $this->service,
            date: $this->date,
            items: $this->items,
            confidence: $this->confidence,
            needsReview: $this->needsReview,
            shouldImport: $this->shouldImport,
            disposition: $this->disposition,
            validationReasons: $this->validationReasons,
            contentValidationReasons: $this->contentValidationReasons,
            holdReasons: $this->holdReasons,
            sourceProvenance: $this->sourceProvenance,
            contentScope: $contentScope,
        );
    }

    /** @return list<string> */
    public function holdReasonValues(): array
    {
        return array_map(
            static fn (OosEmailPlanHoldReason $reason): string => $reason->value,
            $this->holdReasons,
        );
    }

    /**
     * The stored shape, defined once. It was written out independently by the parser and by
     * archive identity resolution, and read back by a third place, so a field added to one copy
     * was silently dropped from every plan that passed through another.
     *
     * @return array{plan_key:string,service:?string,date:?string,content_scope:string,items:array<int,array<string,mixed>>,confidence:float,needs_review:bool,should_import:bool,disposition:string,validation_reasons:list<string>,content_validation_reasons:list<string>,hold_reasons:list<string>,source_provenance:array<string,mixed>}
     */
    public function toMetadataArray(): array
    {
        return [
            'plan_key' => $this->key(),
            'service' => $this->service?->value,
            'date' => $this->date,
            'content_scope' => $this->contentScope->value,
            'items' => $this->items,
            'confidence' => $this->confidence,
            'needs_review' => $this->needsReview,
            'should_import' => $this->shouldImport,
            'disposition' => $this->disposition->value,
            'validation_reasons' => $this->validationReasons,
            'content_validation_reasons' => $this->contentValidationReasons,
            'hold_reasons' => $this->holdReasonValues(),
            'source_provenance' => $this->sourceProvenance,
        ];
    }
}

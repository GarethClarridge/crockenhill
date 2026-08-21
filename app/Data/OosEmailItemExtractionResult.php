<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailItemExtractionResult
{
    /**
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool,semantic_kind?:?string}>  $items  Flattened items across every service plan, kept for backward compatibility.
     * @param  list<string>  $notes
     * @param  list<array{service:?string,date:?string,content_scope?:string,rejected_service?:string,service_evidence_line_ids?:list<int>,items:array<int,array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool,semantic_kind?:?string}>,confidence:float}>  $services  Per-service plans (morning/evening/other); empty for the legacy single-list shape.
     * @param  list<array{line_id:int,reason:string}>  $ignoredLines
     */
    public function __construct(
        public array $items,
        public float $confidence,
        public array $notes = [],
        public array $services = [],
        public ?int $serviceCount = null,
        public array $ignoredLines = [],
        public bool $provenanceComplete = false,
    ) {}

    /**
     * The evidence-artifact shape of an extraction, shared so the candidate parser, the arm runner
     * and a recompilation cannot serialise the same result three different ways.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'confidence' => $this->confidence,
            'notes' => $this->notes,
            'services' => $this->services,
            'service_count' => $this->serviceCount,
            'ignored_lines' => $this->ignoredLines,
            'provenance_complete' => $this->provenanceComplete,
        ];
    }
}

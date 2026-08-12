<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailItemExtractionResult
{
    /**
     * @param  array<int, array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool}>  $items  Flattened items across every service plan, kept for backward compatibility.
     * @param  list<string>  $notes
     * @param  list<array{service:?string,date:?string,content_scope?:string,rejected_service?:string,service_evidence_line_ids?:list<int>,items:array<int,array{type:string,title:string,source_line_ids?:list<int>,continuation?:bool}>,confidence:float}>  $services  Per-service plans (morning/evening/other); empty for the legacy single-list shape.
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
}

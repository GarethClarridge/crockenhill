<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailItemExtractionResult
{
    /**
     * @param  array<int, array{type:string,title:string}>  $items  Flattened items across every service plan, kept for backward compatibility.
     * @param  list<string>  $notes
     * @param  list<array{service:?string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>  $services  Per-service plans (morning/evening/other); empty for the legacy single-list shape.
     */
    public function __construct(
        public array $items,
        public float $confidence,
        public array $notes = [],
        public array $services = [],
    ) {}
}

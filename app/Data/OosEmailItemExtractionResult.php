<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailItemExtractionResult
{
    /**
     * @param  array<int, array{type:string,title:string}>  $items
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $items,
        public float $confidence,
        public array $notes = [],
    ) {}
}

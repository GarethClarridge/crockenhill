<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonService;

readonly class OosEmailParseResult
{
    /**
     * @param  array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>  $items
     * @param  array<string, mixed>  $importMetadata
     */
    public function __construct(
        public ?string $date,
        public ?SermonService $service,
        public array $items,
        public float $confidenceScore,
        public bool $needsReview,
        public bool $shouldImport,
        public array $importMetadata,
    ) {}
}

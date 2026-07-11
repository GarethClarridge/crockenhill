<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;

readonly class OpenLpImportResult
{
    /**
     * @param  array{conflicts?:array<int, array<string, mixed>>}  $syncResult
     * @param  array{
     *     dry_run:bool,
     *     processed:int,
     *     matched:int,
     *     unmatched:int,
     *     updated:int,
     *     unchanged:int,
     *     cleared:int,
     *     match_types:array<string,int>
     * }  $linkResult
     */
    public function __construct(
        public ChurchService $churchService,
        public OpenLpParseResult $parseResult,
        public bool $wasCreated,
        public array $syncResult,
        public array $linkResult,
    ) {}
}

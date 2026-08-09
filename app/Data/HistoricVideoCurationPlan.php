<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonService;
use Illuminate\Support\Carbon;

readonly class HistoricVideoCurationPlan
{
    /**
     * @param  list<array{
     *     manifest_item_key:string,
     *     tag:string,
     *     label:string,
     *     files:list<string>,
     *     source_files:list<array{relative_path:string,sha256:string,byte_size:int}>,
     *     date:Carbon,
     *     service:SermonService,
     *     client_file_date:string,
     *     bytes:int,
     *     manifest_concatenation:string,
     *     manifest_expected_occurrence_count:int
     * }>  $workItems
     * @param  array<string, int>  $counts
     * @param  list<array{item_key:string, exclusion_reason:string, duplicate_of:?string}>  $exclusions
     */
    public function __construct(
        public string $manifestHash,
        public string $planHash,
        public array $workItems,
        public array $counts,
        public array $exclusions = [],
        public ?string $batchKey = null,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'format' => 'crockenhill-historic-video-import-plan',
            'version' => 1,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'batch_key' => $this->batchKey,
            'counts' => $this->counts,
            'items' => array_map(static fn (array $item): array => [
                'item_key' => $item['manifest_item_key'],
                'date' => $item['date']->toDateString(),
                'service' => $item['service']->value,
                'concatenation' => $item['manifest_concatenation'],
                'expected_occurrence_count' => $item['manifest_expected_occurrence_count'],
                'files' => $item['source_files'],
            ], $this->workItems),
            'exclusions' => $this->exclusions,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;

final readonly class ChurchServiceShowReadModel
{
    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  list<string>  $warnings
     * @param  list<ChurchServiceProcessingRunView>  $processingRunViews
     * @param  list<array{label: string, state: string}>  $pipelineSteps
     */
    public function __construct(
        public ChurchService $churchService,
        public array $importMetadata,
        public array $warnings,
        public ?float $confidenceScore,
        public array $processingRunViews,
        public ?PendingStructureMergeMetadata $pendingMerge,
        public ?string $pendingMergeSource,
        public array $pipelineSteps,
    ) {}

    /**
     * @return array{
     *     churchService: ChurchService,
     *     importMetadata: array<string, mixed>,
     *     warnings: list<string>,
     *     confidenceScore: ?float,
     *     processingRunViews: list<ChurchServiceProcessingRunView>,
     *     pendingMerge: ?PendingStructureMergeMetadata,
     *     pendingMergeSource: ?string,
     *     pipelineSteps: list<array{label: string, state: string}>
     * }
     */
    public function toViewData(): array
    {
        return [
            'churchService' => $this->churchService,
            'importMetadata' => $this->importMetadata,
            'warnings' => $this->warnings,
            'confidenceScore' => $this->confidenceScore,
            'processingRunViews' => $this->processingRunViews,
            'pendingMerge' => $this->pendingMerge,
            'pendingMergeSource' => $this->pendingMergeSource,
            'pipelineSteps' => $this->pipelineSteps,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;
use App\Models\ServiceSection;

final readonly class ChurchServiceShowReadModel
{
    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  list<string>  $warnings
     * @param  list<ChurchServiceProcessingRunView>  $processingRunViews
     * @param  list<array{label: string, state: string}>  $pipelineSteps
     * @param  array<int, array{section: ServiceSection, reasons: array<int, array{key: string, label: string, classes: string}>, review_reason: string|null, audio_url: string|null, video_url: string|null}>  $sectionReviewPanels
     * @param  array<int, int>  $mergeCandidatePairs
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
        public array $sectionReviewPanels,
        public array $mergeCandidatePairs,
        public int $pendingApprovalCount,
        public bool $sectionPublishingEnabled,
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
     *     pipelineSteps: list<array{label: string, state: string}>,
     *     sectionReviewPanels: array<int, array{section: ServiceSection, reasons: array<int, array{key: string, label: string, classes: string}>, review_reason: string|null, audio_url: string|null, video_url: string|null}>,
     *     mergeCandidatePairs: array<int, int>,
     *     pendingApprovalCount: int,
     *     sectionPublishingEnabled: bool
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
            'sectionReviewPanels' => $this->sectionReviewPanels,
            'mergeCandidatePairs' => $this->mergeCandidatePairs,
            'pendingApprovalCount' => $this->pendingApprovalCount,
            'sectionPublishingEnabled' => $this->sectionPublishingEnabled,
        ];
    }
}

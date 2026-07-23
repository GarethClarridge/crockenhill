<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ChurchService;
use App\Models\LivestreamSegment;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection;

final readonly class ChurchServiceShowReadModel
{
    /**
     * @param  array<string, mixed>  $importMetadata
     * @param  list<string>  $warnings
     * @param  list<ChurchServiceProcessingRunView>  $processingRunViews
     * @param  list<ChurchServiceProcessingRunView>  $otherProcessingRunViews
     * @param  list<array{label: string, state: string}>  $pipelineSteps
     * @param  array<int, array{section: ServiceSection, reasons: array<int, array{key: string, label: string, classes: string}>, review_reason: string|null, confirmable: bool, audio_url: string|null, video_url: string|null}>  $sectionReviewPanels
     * @param  array<int, int>  $mergeCandidatePairs
     * @param  array<int, array{segments: Collection<int, LivestreamSegment>, confirmed_segment_id: int|null, source_available: bool}>  $segmentConfirmations
     */
    public function __construct(
        public ChurchService $churchService,
        public array $importMetadata,
        public array $warnings,
        public ?float $confidenceScore,
        public array $processingRunViews,
        public ?ChurchServiceProcessingRunView $primaryProcessingRunView,
        public array $otherProcessingRunViews,
        public ?PendingStructureMergeMetadata $pendingMerge,
        public ?string $pendingMergeSource,
        public array $pipelineSteps,
        public ChurchServiceStatusSummary $statusSummary,
        public int $attentionCount,
        public string $planSourceNote,
        public bool $reviewNeedsAttention,
        public array $sectionReviewPanels,
        public array $mergeCandidatePairs,
        public array $segmentConfirmations,
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
     *     primaryProcessingRunView: ?ChurchServiceProcessingRunView,
     *     otherProcessingRunViews: list<ChurchServiceProcessingRunView>,
     *     pendingMerge: ?PendingStructureMergeMetadata,
     *     pendingMergeSource: ?string,
     *     pipelineSteps: list<array{label: string, state: string}>,
     *     statusSummary: ChurchServiceStatusSummary,
     *     attentionCount: int,
     *     planSourceNote: string,
     *     reviewNeedsAttention: bool,
     *     sectionReviewPanels: array<int, array{section: ServiceSection, reasons: array<int, array{key: string, label: string, classes: string}>, review_reason: string|null, confirmable: bool, audio_url: string|null, video_url: string|null}>,
     *     mergeCandidatePairs: array<int, int>,
     *     segmentConfirmations: array<int, array{segments: Collection<int, LivestreamSegment>, confirmed_segment_id: int|null, source_available: bool}>,
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
            'primaryProcessingRunView' => $this->primaryProcessingRunView,
            'otherProcessingRunViews' => $this->otherProcessingRunViews,
            'pendingMerge' => $this->pendingMerge,
            'pendingMergeSource' => $this->pendingMergeSource,
            'pipelineSteps' => $this->pipelineSteps,
            'statusSummary' => $this->statusSummary,
            'attentionCount' => $this->attentionCount,
            'planSourceNote' => $this->planSourceNote,
            'reviewNeedsAttention' => $this->reviewNeedsAttention,
            'sectionReviewPanels' => $this->sectionReviewPanels,
            'mergeCandidatePairs' => $this->mergeCandidatePairs,
            'segmentConfirmations' => $this->segmentConfirmations,
            'pendingApprovalCount' => $this->pendingApprovalCount,
            'sectionPublishingEnabled' => $this->sectionPublishingEnabled,
        ];
    }
}

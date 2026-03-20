<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class OosAlignmentService
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly SongSectionAligner $songAligner,
        private readonly StructuralSectionAligner $structuralAligner,
        private readonly UnmatchedSongReviewApplicator $unmatchedSongApplicator,
        private readonly AlignmentTriggerCalculator $triggerCalculator,
        private readonly ChurchServiceReviewSynchronizer $reviewSynchronizer,
        private readonly SectionAlignmentBaselineRestorer $baselineRestorer,
    ) {}

    /**
     * @return array{
     *     aligned: bool,
     *     review_triggers: array<int, string>,
     *     matched_song_sections: int,
     *     unmatched_song_sections: int,
     *     structure_mismatches: int,
     *     low_confidence_sections: int
     * }
     */
    public function alignForProcessingLog(
        MediaProcessingLog $processingLog,
        ?ChurchService $churchService = null,
        bool $lateArrival = false
    ): array {
        $freshLog = MediaProcessingLog::query()->find($processingLog->id);

        if (! $freshLog instanceof MediaProcessingLog) {
            return $this->emptyResult();
        }

        return DB::transaction(function () use ($freshLog, $churchService, $lateArrival): array {
            $churchService = $this->resolveChurchService($freshLog, $churchService);

            if (! $churchService instanceof ChurchService) {
                return $this->emptyResult();
            }

            /** @var EloquentCollection<int, ChurchServiceItem> $items */
            $items = $churchService->items()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                return $this->emptyResult();
            }

            /** @var EloquentCollection<int, ServiceSection> $sections */
            $sections = ServiceSection::query()
                ->where('media_processing_log_id', $freshLog->id)
                ->orderBy('section_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($sections->isEmpty()) {
                return $this->emptyResult();
            }

            $beforeState = $this->triggerCalculator->captureAlignmentState($sections);

            foreach ($sections as $section) {
                $this->baselineRestorer->prepare($section);
            }

            $matchedSongSectionIds = $this->songAligner->align($sections, $items);
            $structureMismatchCount = $this->structuralAligner->align($sections, $items);

            $unmatchedSongSections = $this->unmatchedSongApplicator->apply($sections, $matchedSongSectionIds);

            $reviewTriggers = $this->triggerCalculator->calculate(
                $sections,
                $structureMismatchCount,
                $unmatchedSongSections,
                $beforeState,
                $lateArrival
            );

            foreach ($sections as $section) {
                $this->baselineRestorer->persistConfidenceLevel($section);
                $section->save();
            }

            $this->reviewSynchronizer->sync($churchService, $sections, $reviewTriggers);

            return [
                'aligned' => true,
                'review_triggers' => array_values($reviewTriggers),
                'matched_song_sections' => count($matchedSongSectionIds),
                'unmatched_song_sections' => $unmatchedSongSections->count(),
                'structure_mismatches' => $structureMismatchCount,
                'low_confidence_sections' => $this->triggerCalculator->lowConfidenceSectionCount($sections),
            ];
        });
    }

    private function resolveChurchService(MediaProcessingLog $processingLog, ?ChurchService $churchService): ?ChurchService
    {
        if ($churchService instanceof ChurchService) {
            if ($processingLog->church_service_id !== $churchService->id) {
                $processingLog->forceFill([
                    'church_service_id' => $churchService->id,
                ])->saveQuietly();
            }

            return $churchService->fresh();
        }

        if ($processingLog->churchService instanceof ChurchService) {
            return $processingLog->churchService;
        }

        if ($processingLog->church_service_id !== null) {
            return $processingLog->churchService()->first();
        }

        $identity = $this->identityResolver->resolve($processingLog);

        if ($identity === null) {
            return null;
        }

        $resolved = ChurchService::query()
            ->where('date', $identity['date'])
            ->where('service', $identity['service']->value)
            ->first();

        if ($resolved instanceof ChurchService) {
            $processingLog->forceFill([
                'church_service_id' => $resolved->id,
            ])->saveQuietly();
        }

        return $resolved;
    }

    /**
     * @return array{
     *     aligned: bool,
     *     review_triggers: array<int, string>,
     *     matched_song_sections: int,
     *     unmatched_song_sections: int,
     *     structure_mismatches: int,
     *     low_confidence_sections: int
     * }
     */
    private function emptyResult(): array
    {
        return [
            'aligned' => false,
            'review_triggers' => [],
            'matched_song_sections' => 0,
            'unmatched_song_sections' => 0,
            'structure_mismatches' => 0,
            'low_confidence_sections' => 0,
        ];
    }
}

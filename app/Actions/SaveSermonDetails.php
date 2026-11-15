<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PreacherSource;
use App\Enums\SermonReferenceProvenance;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Preacher\PreacherResolutionService;
use App\Services\Processing\SermonIdentitySyncService;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

class SaveSermonDetails
{
    use SanitizesLogData;

    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
        private readonly SermonIdentitySyncService $sermonIdentitySyncService,
        private readonly QueueScriptureEnrichment $queueScriptureEnrichment,
        private readonly FlagPublishedReferenceContradictsSermon $flagPublishedReferenceContradiction,
    ) {}

    /**
     * @param  array{
     *   title: string, slug: string, date: string, service: string,
     *   preacher: string, preacherId: ?int, preacherSource: ?string,
     *   preacherConfidence: ?float, duration: ?float,
     *   segmentStartTime: ?float, segmentEndTime: ?float,
     *   downloadCount: ?int, reference: ?string, series: ?string,
     *   summary: ?string, points: array<int,string>,
     *   showSummary: bool, showPoints: bool, partialRecording: bool
     * } $validated
     */
    public function execute(Sermon $sermon, array $validated): void
    {
        if ($validated['preacherId']) {
            $preacher = Preacher::query()->find($validated['preacherId']);
        } else {
            $preacher = $this->preacherResolutionService->resolve($validated['preacher']);
        }

        if (! ($preacher instanceof Preacher)) {
            $preacher = null;
        }

        $referenceChanged = $sermon->reference !== $validated['reference'];
        $newReference = $validated['reference'];
        $updateData = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $preacher ? $preacher->name : $validated['preacher'],
            'preacher_id' => $preacher?->id,
            'preacher_source' => $preacher ? PreacherSource::Manual->value : $validated['preacherSource'],
            'preacher_confidence' => $validated['preacherConfidence'],
            'duration' => $validated['duration'],
            'segment_start_time' => $validated['segmentStartTime'],
            'segment_end_time' => $validated['segmentEndTime'],
            'download_count' => $validated['downloadCount'] ?? 0,
            'needs_preacher_review' => false,
            ...$this->referenceUpdates($sermon, $newReference, $validated['partialRecording']),
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($validated['points']),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ];

        $sermon->update($updateData);

        $fresh = $sermon->fresh();
        Log::warning('Sermon updated by admin', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'sermon_id' => $sermon->id,
            'title' => $fresh instanceof Sermon ? $fresh->title : $sermon->title,
            'slug' => $fresh instanceof Sermon ? $fresh->slug : $sermon->slug,
        ]));

        $this->afterReferenceSave($sermon, $referenceChanged, $newReference);
    }

    /** Apply a hand-ruled reference and disclosure without re-ruling the rest of the editor form. */
    public function correctReference(Sermon $sermon, ?string $reference, bool $partialRecording): void
    {
        $changed = $sermon->reference !== $reference;
        $sermon->update([
            ...$this->referenceUpdates($sermon, $reference, $partialRecording),
            'reference_provenance' => SermonReferenceProvenance::Curated,
        ]);
        Log::warning('Sermon reference corrected by admin', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'sermon_id' => $sermon->id,
            'reference' => $sermon->reference,
            'partial_recording' => $sermon->partial_recording,
        ]));
        $this->afterReferenceSave($sermon, $changed, $reference);
    }

    /** @return array{reference: ?string, reference_provenance: ?SermonReferenceProvenance, scripture_passage_id: ?int, partial_recording: bool} */
    private function referenceUpdates(Sermon $sermon, ?string $reference, bool $partialRecording): array
    {
        $changed = $sermon->reference !== $reference;
        $passage = $this->sermonIdentitySyncService->findExistingScripturePassage($reference);

        return [
            'reference' => $reference,
            'reference_provenance' => $changed ? SermonReferenceProvenance::Curated : $sermon->reference_provenance,
            'scripture_passage_id' => ($changed || $sermon->scripture_passage_id === null)
                ? $passage?->id
                : $sermon->scripture_passage_id,
            'partial_recording' => $partialRecording,
        ];
    }

    private function afterReferenceSave(Sermon $sermon, bool $referenceChanged, ?string $newReference): void
    {
        if ($referenceChanged && ! empty($newReference)) {
            $this->queueScriptureEnrichment->dispatch($sermon->fresh() ?? $sermon);
        }

        $run = $sermon->latestProcessingLog;

        if ($referenceChanged && $run instanceof MediaProcessingLog) {
            ($this->flagPublishedReferenceContradiction)($run);
        }
    }
}
